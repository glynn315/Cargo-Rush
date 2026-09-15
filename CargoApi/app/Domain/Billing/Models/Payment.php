<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\InvoiceDirection;
use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money that moved.
 *
 * One event, recorded once, and then **applied** to one or more invoices
 * through `payment_allocations`. That split is the whole design: a payment
 * belongs to the day a cheque cleared, and where it was put is a separate
 * decision that can span four documents or half of one.
 *
 * Nothing here is derived from an invoice. An invoice's status is derived from
 * *these*, which is the direction that keeps them from disagreeing.
 */
class Payment extends Model
{
    use BelongsToCompany, HasUlids, SoftDeletes;

    protected $fillable = [
        'customer_id', 'direction', 'amount_cents', 'currency',
        'paid_on', 'method', 'reference', 'notes', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'paid_on' => 'date',
            'direction' => InvoiceDirection::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(Invoice::class, 'payment_allocations')
            ->withPivot('amount_cents')
            ->withTimestamps();
    }

    /**
     * How much of this payment has been put against a document.
     *
     * Usually the whole of it. The gap is a real state and worth being able to
     * see: a customer who pays a round ₱100,000 against ₱97,340 of invoices
     * has ₱2,660 sitting unapplied, and that is a credit on their account
     * rather than an error — it goes against whatever they are billed next.
     */
    public function allocatedCents(): int
    {
        return (int) $this->allocations()->sum('amount_cents');
    }

    /** The part not yet put anywhere. Zero on a payment applied in full. */
    public function unallocatedCents(): int
    {
        return max(0, $this->amount_cents - $this->allocatedCents());
    }
}
