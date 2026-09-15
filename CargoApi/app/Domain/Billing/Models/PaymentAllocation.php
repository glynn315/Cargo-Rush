<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Tenancy\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a payment was put.
 *
 * A row is "this much of that payment, against this invoice". Many per payment
 * is one cheque clearing four documents; many per invoice is a document paid
 * off in instalments. Both were impossible while payment was a column on the
 * invoice, and both are what customers actually do.
 *
 * No soft deletes, unlike almost everything else here. Un-applying a payment
 * is not a record worth keeping — the payment itself is the record, and a
 * deleted allocation that still counted would make an invoice look paid by
 * money that is now sitting somewhere else.
 */
class PaymentAllocation extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = ['payment_id', 'invoice_id', 'amount_cents'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
