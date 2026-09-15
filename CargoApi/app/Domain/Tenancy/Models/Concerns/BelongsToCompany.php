<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models\Concerns;

use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Scopes\CompanyScope;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * What makes a model one company's.
 *
 * Two halves, and a model needs both to be safe. The scope decides what a read
 * can see; this hook decides what a write is stamped with. A model with only
 * the first would let a company create rows it could then never find again.
 *
 * `company_id` is never in `$fillable` on any model that uses this, and that is
 * deliberate — it is not a field a form fills in. It comes from the account
 * that made the request and from nowhere else, so a payload carrying
 * `company_id` is ignored rather than obeyed.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function (self $model): void {
            if ($model->company_id !== null) {
                return;
            }

            $tenant = app(Tenant::class);

            // `nobody()` is a company no row has — a request scoped to nothing,
            // for an account that belongs to nobody yet. Stamping it onto a row
            // would write something nobody can ever read, so it is treated as
            // no company at all and refused below.
            if ($tenant->id() !== null && ! $tenant->isNobody()) {
                $model->company_id = $tenant->id();

                return;
            }

            if ($model->mayHaveNoCompany()) {
                return;
            }

            // Loud, and on purpose. The alternative is a row with no owner:
            // invisible to the company that made it, and visible to everybody
            // once somebody writes a query that forgets to filter. A console
            // command that means to write should say which company it is
            // writing for — `Tenant::use()`.
            throw new RuntimeException(sprintf(
                'Refusing to create a %s with no company. Wrap the write in Tenant::use($company, ...) '
                .'to say which company it belongs to.',
                static::class,
            ));
        });
    }

    /**
     * May a row of this kind exist with no company?
     *
     * No, for every table a haulier owns, and the throw above is why. One model
     * says otherwise and it is worth reading as an exception rather than as a
     * setting: a `users` row for a shipper who has signed up and not yet chosen
     * a carrier belongs to nobody, because there is nobody for it to belong to
     * until they pick. See `User::mayHaveNoCompany()`, which allows exactly
     * that account and no other.
     */
    protected function mayHaveNoCompany(): bool
    {
        return false;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * A query with the tenant filter lifted, for this model only.
     *
     * Rare and deliberate: registering a company checks whether a slug is
     * taken, and a scheduled sweep works across all of them. Every use is a
     * decision to read outside the current company, so it reads like one at the
     * call site instead of being a flag on an ordinary query.
     */
    public static function acrossCompanies(): Builder
    {
        return static::query()->withoutGlobalScope(CompanyScope::class);
    }
}
