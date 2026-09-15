<?php

declare(strict_types=1);

namespace App\Domain\Billing\Repositories;

use App\Domain\Billing\Models\Payment;
use App\Domain\Shared\Repositories\Repository;
use Illuminate\Database\Eloquent\Builder;

class PaymentRepository extends Repository
{
    protected function model(): string
    {
        return Payment::class;
    }

    public function query(): Builder
    {
        // Newest first: the payments screen is a register, and the thing
        // somebody just entered should be at the top of it.
        return Payment::query()
            ->with('customer:id,name')
            ->withSum('allocations', 'amount_cents')
            ->orderByDesc('paid_on')
            ->orderByDesc('created_at');
    }

    protected function searchable(): array
    {
        // The reference above all: it is what somebody has in front of them
        // off a bank statement when they come looking.
        return ['reference', 'notes'];
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        $query = parent::applyFilters($query, $filters);

        if (! empty($filters['direction'])) {
            $query->where('direction', $filters['direction']);
        }

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        // The period a reconciliation is run over.
        if (! empty($filters['from'])) {
            $query->whereDate('paid_on', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('paid_on', '<=', $filters['to']);
        }

        return $query;
    }
}
