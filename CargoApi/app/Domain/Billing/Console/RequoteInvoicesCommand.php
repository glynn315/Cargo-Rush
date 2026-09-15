<?php

declare(strict_types=1);

namespace App\Domain\Billing\Console;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Services\TaxService;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Tenancy\Console\Concerns\RunsPerCompany;
use Illuminate\Console\Command;

/**
 * Repair invoices that two now-fixed bugs left with the wrong figures.
 *
 * A one-off, and written as a command rather than run as SQL because it has to
 * think: the correct figures come from the tariff and the customer's own tax
 * treatment, which is arithmetic that already exists in `TaxService` and should
 * not be reimplemented in an UPDATE statement.
 *
 * ## What it repairs
 *
 * **VAT charged twice.** A write's `amount_cents` means the taxable base and a
 * read's means net plus VAT. The billing form read the second and posted it
 * back as the first, so re-saving an invoice put 12% on a figure that already
 * had 12% in it. A ₱7,530 haul went out at ₱8,433.60, and again at ₱9,445.63.
 * Any receivable raised by a delivery can be checked against the one figure
 * that was never touched — the trip's own price — and re-quoted from it.
 *
 * **Paid with nothing behind it.** `paid` and `partial` follow from the
 * payments recorded against a document; a form could write them directly, and
 * one that did produced a receivable marked settled with no money against it.
 * The customer's portal read those as collected, so a firm was told ₱21,482
 * had arrived when the bank had seen none of it.
 *
 * ## What it will not do
 *
 * It leaves a **manual** invoice's figures alone. There is nothing to check one
 * against: the base was typed by somebody, and this cannot tell a re-taxed
 * ₱8,433.60 from a haul that genuinely cost that. Those are listed at the end
 * so a person can look at them.
 *
 * It never touches a document with a **payment** against it. Money has moved
 * against that number, a customer has a copy, and quietly restating it is how a
 * reconciliation stops adding up. Those are listed too.
 *
 *     php artisan cargo:invoices-requote --dry-run   # what it would change
 *     php artisan cargo:invoices-requote             # change it
 */
class RequoteInvoicesCommand extends Command
{
    use RunsPerCompany;

    protected $signature = 'cargo:invoices-requote {--dry-run : Report what would change, and change nothing}';

    protected $description = 'Re-quote invoices left double-taxed, and clear a paid status with no payment behind it';

    public function handle(TaxService $tax): int
    {
        $pretend = (bool) $this->option('dry-run');

        if ($pretend) {
            $this->warn('Dry run: nothing will be written.');
        }

        return $this->eachCompany(function () use ($tax, $pretend): void {
            $requoted = 0;
            $unflagged = 0;
            $skipped = [];

            $invoices = Invoice::query()
                ->with(['trip:id,reference,price_cents', 'customer'])
                ->withSum('allocations', 'amount_cents')
                ->where('direction', InvoiceDirection::Receivable->value)
                ->orderBy('number')
                ->get();

            foreach ($invoices as $invoice) {
                $paid = $invoice->paidCents();

                // A status that claims money nobody received. Cleared first and
                // on its own, because it is wrong whatever the figures say —
                // and `statusFromPayments()` is the same derivation the payment
                // service uses, so a part-paid document lands on `partial`
                // rather than being flipped all the way back to pending.
                if ($paid === 0 && in_array($invoice->status, [StatusValue::Paid, StatusValue::Partial], true)) {
                    $this->line(sprintf(
                        '  %s · %s → %s (no payment against it)',
                        $invoice->number,
                        $invoice->status->value,
                        $invoice->statusFromPayments()->value,
                    ));

                    if (! $pretend) {
                        $invoice->forceFill([
                            'status' => $invoice->statusFromPayments()->value,
                            'paid_at' => null,
                        ])->save();
                    }

                    $unflagged++;
                }

                $trip = $invoice->trip;

                if ($trip === null || $trip->price_cents <= 0) {
                    $skipped[] = sprintf('%s (raised by hand — check it yourself)', $invoice->number);

                    continue;
                }

                if ($paid > 0) {
                    $skipped[] = sprintf('%s (money against it — left alone)', $invoice->number);

                    continue;
                }

                // The figures this document *should* carry, from the price the
                // customer was quoted and the tax treatment on their record.
                $correct = $tax->on($trip->price_cents, $invoice->customer, InvoiceDirection::Receivable);
                $columns = $correct->columns();

                if ((int) $columns['amount_cents'] === (int) $invoice->amount_cents
                    && (int) $columns['net_amount_cents'] === (int) $invoice->net_amount_cents) {
                    continue;
                }

                $this->line(sprintf(
                    '  %s · %s: %s → %s (net %s → %s)',
                    $invoice->number,
                    $trip->reference,
                    $this->pesos((int) $invoice->amount_cents),
                    $this->pesos((int) $columns['amount_cents']),
                    $this->pesos((int) $invoice->net_amount_cents),
                    $this->pesos((int) $columns['net_amount_cents']),
                ));

                if (! $pretend) {
                    $invoice->forceFill($columns)->save();
                }

                $requoted++;
            }

            $this->info(sprintf(
                '%s %d invoice(s), cleared %d wrongly-paid status(es).',
                $pretend ? 'Would re-quote' : 'Re-quoted',
                $requoted,
                $unflagged,
            ));

            foreach ($skipped as $note) {
                $this->comment("  skipped: {$note}");
            }
        });
    }

    private function pesos(int $cents): string
    {
        return '₱'.number_format($cents / 100, 2);
    }
}
