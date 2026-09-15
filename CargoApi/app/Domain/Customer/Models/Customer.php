<?php

declare(strict_types=1);

namespace App\Domain\Customer\Models;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Enums\VatTreatment;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use App\Domain\Trip\Models\Trip;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use BelongsToCompany, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'name', 'contact', 'address', 'latitude', 'longitude', 'rating', 'status',
        'tin', 'vat_treatment', 'withholds_tax', 'withholding_rate_bp',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'float',
            // Numbers, not the strings a decimal column hands back: the clients
            // put these straight on a map.
            'latitude' => 'float',
            'longitude' => 'float',
            'status' => StatusValue::class,
            'vat_treatment' => VatTreatment::class,
            'withholds_tax' => 'boolean',
            'withholding_rate_bp' => 'integer',
        ];
    }

    /**
     * How VAT applies when we bill this firm.
     *
     * A property of who is being billed rather than of what was hauled, which
     * is why it lives here and not on the trip or the invoice form. Defaults to
     * vatable, because most customers are and an invoice that quietly omitted
     * VAT would be one the business has under-collected on.
     */
    /**
     * Has this firm said where it is?
     *
     * Only ever true for a shipper who signed themselves up and pinned their
     * store — the desk has no field for it, and does not need one: a customer
     * the office added is rung about their pickups.
     */
    public function isPinned(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function vatTreatment(): VatTreatment
    {
        return $this->vat_treatment ?? VatTreatment::Vatable;
    }

    /**
     * Does this customer keep back withholding tax when they pay?
     *
     * Also theirs rather than ours: a government agency or a large corporate
     * is a withholding agent, a small trader is not. Defaults to **false**,
     * and deliberately the opposite way round from VAT — assuming a customer
     * withholds when they do not means expecting less money than is coming,
     * which shows up as a phantom short payment on every invoice.
     */
    public function withholdsTax(): bool
    {
        return (bool) $this->withholds_tax;
    }

    /**
     * Credentials just created for this firm, on the instance that created
     * them — never read from the database, because a password hash is not
     * something to read back.
     *
     * The create response is the one chance the office has to see the starting
     * password and pass it on, so `CustomerResource` prints it from here and
     * nowhere else. Null on every other instance, which is every read.
     *
     * @var array{email: string, password: string}|null
     */
    public ?array $newLogin = null;

    /**
     * The accounts that sign in for this firm.
     *
     * Plural on purpose: two people at the same company can each have a login
     * and see the same deliveries and the same invoices, because the history
     * belongs to the firm and not to whoever is holding the phone.
     */
    public function logins(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * The days of income and expenses recorded against this customer's work.
     *
     * A trip says the haul happened and an invoice says it was billed; this is
     * what it actually earned and cost. History showed the first two and never
     * the third, so a customer could have a month of hauling behind them and
     * no money anywhere on the record.
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * What they still owe, in centavos. Derived from unsettled receivables, so
     * it can never drift from the Billing module.
     */
    public function outstandingCents(): int
    {
        return $this->invoices()
            ->where('direction', InvoiceDirection::Receivable->value)
            /**
             * The only status that takes a document out of the sum.
             *
             * Cancelled is a decision — a withdrawn document is not owed, and
             * no arithmetic can tell you that. Everything else is *measured*:
             * a settled invoice has a zero balance and drops out on its own, so
             * there is nothing to gain by also trusting a flag that says so —
             * and a great deal to lose, since a document flagged paid with no
             * payment behind it then reads as nothing owed. It did.
             */
            ->where('status', '!=', StatusValue::Cancelled->value)
            ->withSum('allocations', 'amount_cents')
            ->get()
            ->sum(static fn (Invoice $invoice): int => $invoice->balanceCents());
    }

    /**
     * What this firm has actually paid us.
     *
     * Summed from the payments allocated to their receivables, and not from
     * the invoices whose *status* says paid. The difference is the whole point:
     * a status is a stored opinion about a document, and money that has arrived
     * is a row in `payment_allocations`. When the two disagreed — a document
     * flagged paid with nothing underneath it — the customer's portal told them
     * ₱21,482 had been collected while the bank had seen none of it.
     *
     * The same reasoning as `Invoice::paidCents()`, which is what this adds up:
     * a total that can disagree with the payments beneath it is the one thing
     * this codebase refuses to keep.
     */
    public function paidCents(): int
    {
        return (int) PaymentAllocation::query()
            ->whereHas(
                'invoice',
                fn ($query) => $query
                    ->where('customer_id', $this->getKey())
                    ->where('direction', InvoiceDirection::Receivable->value),
            )
            ->sum('amount_cents');
    }
}
