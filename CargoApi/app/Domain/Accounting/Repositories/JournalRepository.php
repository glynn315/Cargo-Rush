<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Repositories;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Shared\Repositories\Repository;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reading the general journal.
 *
 * The filters are the four questions somebody actually opens a journal with:
 * *when*, *what kind*, *which state*, and *which account*. The last is the odd
 * one out and the most useful — an entry does not have an account, its lines
 * do, so it is a `whereHas` rather than a column comparison. It answers "show
 * me everything that touched 5300" from the journal side, which is a different
 * question from the ledger's "show me 5300", and both get asked.
 */
class JournalRepository extends Repository
{
    protected function model(): string
    {
        return JournalEntry::class;
    }

    public function query(): Builder
    {
        return JournalEntry::query()
            ->with(['lines.account:id,code,name,type', 'postedBy:id,name'])
            ->inJournalOrder();
    }

    protected function searchable(): array
    {
        return ['reference', 'memo'];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function applyFilters(Builder $query, array $filters): Builder
    {
        // `status`, `search` and the rest of the shared vocabulary first.
        $query = parent::applyFilters($query, $filters);

        if (! empty($filters['category'])) {
            $query->whereIn('category', (array) $filters['category']);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('entry_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('entry_date', '<=', $filters['to']);
        }

        /**
         * Every entry with a line against this account.
         *
         * Through the relation rather than a join, so the tenant scope applies
         * to the lines as well — a join written by hand here would be one more
         * place that has to remember, and the day it forgot it would read
         * another company's postings.
         */
        if (! empty($filters['account_id'])) {
            $query->whereHas(
                'lines',
                fn (Builder $lines): Builder => $lines->where('account_id', $filters['account_id']),
            );
        }

        return $query;
    }
}
