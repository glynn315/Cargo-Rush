<?php

declare(strict_types=1);

namespace App\Domain\Billing\Controllers;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Requests\InvoiceRequest;
use App\Domain\Billing\Resources\InvoiceResource;
use App\Domain\Billing\Services\BillingService;
use App\Domain\Billing\Services\InvoiceDocumentService;
use App\Domain\Billing\Services\StatementOfAccountService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Billing & Invoice — receivables, payables, payment history. */
class BillingController extends ApiController
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly InvoiceDocumentService $documents,
        private readonly StatementOfAccountService $statements,
    ) {}

    /**
     * `GET billing/statement/{customer}` — one firm's running account.
     *
     * An opening balance, every document and payment in date order, a closing
     * balance that follows from them, and the aging of what is left. The
     * document a collections call is made from — and the one a customer's
     * accounts department asks for when their records and ours have stopped
     * agreeing.
     *
     * `direction=payable` turns it into a supplier statement: the same report,
     * read the other way round, because one party's receivable is the other's
     * payable.
     */
    public function statement(Request $request, Customer $customer): JsonResponse
    {
        $direction = $request->string('direction')->toString() === InvoiceDirection::Payable->value
            ? InvoiceDirection::Payable
            : InvoiceDirection::Receivable;

        return $this->payload($this->statements->forCustomer(
            $customer,
            $this->date($request, 'from'),
            $this->date($request, 'to'),
            $direction,
        ));
    }

    /**
     * A date off the query string, or null.
     *
     * A statement with no range covers everything, which is the honest answer
     * to an unreadable date on a report rather than an error page.
     */
    private function date(Request $request, string $key): ?Carbon
    {
        $value = trim((string) $request->query($key, ''));

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * `GET billing/{invoice}/document` — the invoice as a document.
     *
     * Everything a printable delivery invoice has to carry, in one payload:
     * who is billing and their TIN, who is being billed and theirs, the haul it
     * is for, the tax lines, the amount in words, and every payment that has
     * landed against it. `InvoiceDocumentService` says why it is assembled
     * server-side rather than stitched together by a client.
     *
     * `billing.view`, like the rest of the reads. Printing an invoice is not a
     * change to it — and the office that can see the figure is the office that
     * hands the customer the paper.
     */
    public function document(Invoice $invoice): JsonResponse
    {
        return $this->payload($this->documents->build($invoice));
    }

    /**
     * `GET billing/export` — the list as it is on screen, as a spreadsheet.
     *
     * The same filters as `index`, because the export somebody wants is the
     * one they are looking at: filter to overdue receivables for one customer,
     * press export, and that is the file. An export that ignored the filters
     * would be a second thing to explain.
     *
     * Streamed rather than built in memory, and unpaginated on purpose: a
     * spreadsheet of the first 25 rows is not an export. The five tax figures
     * are all there — a file with only the gross on it cannot be reconciled
     * against a bank statement, which is the reason anybody exports this.
     */
    public function export(Request $request): StreamedResponse
    {
        $invoices = $this->billing->all($this->filters($request));

        $filename = sprintf('invoices-%s.csv', now()->format('Y-m-d'));

        return response()->streamDownload(function () use ($invoices): void {
            $out = fopen('php://output', 'wb');

            // A BOM, so Excel opens a UTF-8 file as UTF-8: without it, a
            // customer called "Señor" arrives mangled and the office blames
            // the system rather than the spreadsheet.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'Number', 'Direction', 'Status', 'Customer', 'Trip',
                'Issued', 'Due', 'Currency',
                'Net', 'VAT', 'Total', 'Withholding', 'Due to us', 'Paid', 'Balance',
                'VAT treatment', 'Paid on',
            ]);

            foreach ($invoices as $invoice) {
                fputcsv($out, [
                    $invoice->number,
                    $invoice->direction->value,
                    $invoice->status->value,
                    $invoice->counterparty(),
                    $invoice->trip?->reference,
                    $invoice->issued_at?->toDateString(),
                    $invoice->due_at?->toDateString(),
                    $invoice->currency,
                    // Pesos with two decimals, not centavos: this file is read
                    // by a person in a spreadsheet, and it is the one place in
                    // the system where a formatted amount is the right answer.
                    $this->pesos($invoice->net_amount_cents),
                    $this->pesos($invoice->vat_cents),
                    $this->pesos($invoice->amount_cents),
                    $this->pesos($invoice->withholding_cents),
                    $this->pesos($invoice->dueCents()),
                    $this->pesos($invoice->paidCents()),
                    $this->pesos($invoice->balanceCents()),
                    $invoice->vat_treatment?->value,
                    $invoice->paid_at?->toDateString(),
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Centavos to a plain decimal, for a spreadsheet cell to add up. */
    private function pesos(?int $cents): string
    {
        return number_format(((int) $cents) / 100, 2, '.', '');
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->billing->paginate($this->filters($request), $this->perPage($request));

        return $this->collection(InvoiceResource::collection($page), $page);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        return $this->item(new InvoiceResource($invoice));
    }

    public function store(InvoiceRequest $request): JsonResponse
    {
        return $this->item(new InvoiceResource($this->billing->create($request->toData())), status: 201);
    }

    public function update(InvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        return $this->item(new InvoiceResource($this->billing->update($invoice, $request->toData())));
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        $this->billing->delete($invoice);

        return $this->noContent();
    }

    /**
     * Marking one paid, as a verb rather than a status PATCH.
     *
     * Still one click, and it now leaves a payment behind it: the balance, on
     * the day given or today, with whatever method and reference the caller
     * supplied. The invoice's status follows from that payment rather than
     * being set directly — which is what stops a document reading `paid` with
     * nothing underneath it.
     */
    public function settle(Request $request, Invoice $invoice): JsonResponse
    {
        $details = $request->validate([
            'paid_on' => ['sometimes', 'date', 'before_or_equal:today'],
            'method' => ['sometimes', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->item(new InvoiceResource(
            $this->billing->settle($invoice, $details, $request->user()?->getAuthIdentifier()),
        ));
    }

    /** Receivable against payable — the two numbers the page leads with. */
    public function totals(): JsonResponse
    {
        return $this->payload($this->billing->totals());
    }

    /**
     * Receivables by how late they are.
     *
     * `totals()` says what is outstanding; this says how worried to be about
     * it. Money nine days late and money ninety days late are different
     * problems with different phone calls behind them, and the page could not
     * previously tell them apart.
     */
    public function aging(Request $request): JsonResponse
    {
        $direction = $request->string('direction')->toString() === InvoiceDirection::Payable->value
            ? InvoiceDirection::Payable
            : InvoiceDirection::Receivable;

        return $this->payload($this->billing->aging($direction));
    }
}
