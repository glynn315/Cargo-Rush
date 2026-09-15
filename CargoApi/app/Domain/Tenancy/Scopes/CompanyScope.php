<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Scopes;

use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * The one `where` that keeps two hauliers apart.
 *
 * Applied to every tenant model, so it is on the query before a repository has
 * written a line of it — `Trip::query()`, a `whereHas`, an eager load, a
 * relation, a `withTrashed()` count used to work out the next reference. There
 * is no code path that builds a query on one of these tables and does not get
 * it, which is the entire point: correctness here cannot depend on thirty
 * repositories each remembering.
 *
 * It qualifies the column with the table name because these queries join. An
 * unqualified `company_id` in a statement touching both `delivery_logs` and
 * `trips` is ambiguous, and MySQL says so at runtime rather than at boot.
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(Tenant::class);

        // Nothing in force — the console, or a platform-wide sweep that has
        // said so explicitly. See the note on `Tenant`.
        if (! $tenant->check()) {
            return;
        }

        // `Tenant::NOBODY` needs no branch of its own and deliberately does not
        // get one: it is an id no company has, so the ordinary comparison below
        // matches nothing and an account that belongs to nobody yet reads
        // nobody's books.
        $builder->where($model->qualifyColumn('company_id'), $tenant->id());
    }
}
