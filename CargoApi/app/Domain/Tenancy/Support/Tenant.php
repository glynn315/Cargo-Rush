<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Support;

use App\Domain\Tenancy\Models\Company;
use Closure;

/**
 * Which company the code currently running belongs to.
 *
 * One object, resolved as a singleton, holding one id. Everything that scopes
 * data reads it: the global scope on every tenant model, the trait that stamps
 * `company_id` on an insert, and the handful of places that need to say
 * explicitly which company they are acting for.
 *
 * It is set in exactly two ways, and the distinction is worth keeping:
 *
 *   `BindTenant` sets it from the authenticated user on every API request. That
 *   is the only path a client can influence, and it cannot be influenced — the
 *   company comes off the account, never off a header or a parameter, so there
 *   is nothing for a caller to send that would point them at somebody else's
 *   data.
 *
 *   `use()` sets it deliberately, for a console command working through the
 *   companies in turn, for provisioning one that does not exist yet, and for
 *   tests. It always restores what was there before, so nesting is safe and a
 *   command cannot leak a tenant into whatever runs after it.
 *
 * **No tenant set means no filtering.** In a request that cannot happen —
 * `BindTenant` runs on the authenticated group and a request without an account
 * never reaches a repository. On the console it is the useful default: the
 * nightly sweeps that take trips overdue and invoices past due are the
 * platform's work across every company, not one company's work.
 *
 * Which is why there is a third state, `nobody()`, and why it is not the same
 * as having nothing in force. A self-registered shipper who has not yet chosen
 * a haulier belongs to no company, and the safe reading of that is *no rows*
 * rather than *all rows*. `nobody()` puts an id in force that no company has,
 * so every scoped read comes back empty and every scoped write is refused —
 * fail-closed, which is the only acceptable direction for the one property
 * that keeps two hauliers apart.
 */
class Tenant
{
    /**
     * The company that does not exist.
     *
     * Four characters where every real id is a 26-character ULID, so it cannot
     * collide with a company however many are created. A real value in the
     * `where` rather than a flag the scope has to check: a comparison that
     * matches nothing needs no special case anywhere, and a special case is
     * exactly the sort of thing one query in thirty forgets.
     */
    public const NOBODY = 'none';

    private ?string $companyId = null;

    private ?Company $company = null;

    /** The company id in force, or null when nothing is scoped. */
    public function id(): ?string
    {
        return $this->companyId;
    }

    public function check(): bool
    {
        return $this->companyId !== null;
    }

    /**
     * The company record, loaded once and kept.
     *
     * Null when nothing is scoped, and also null for an id whose row has since
     * been deleted — callers that need the record say what to do about that
     * rather than being handed a half-built object.
     */
    public function company(): ?Company
    {
        if ($this->companyId === null || $this->isNobody()) {
            return null;
        }

        if ($this->company?->getKey() !== $this->companyId) {
            // `withoutTenancy` because `companies` is the one table that is not
            // scoped by a company — it *is* the companies.
            $this->company = Company::query()->find($this->companyId);
        }

        return $this->company;
    }

    /** Put a company in force for the rest of this request or command. */
    public function set(Company|string|null $company): void
    {
        $this->companyId = $company instanceof Company ? $company->getKey() : $company;
        $this->company = $company instanceof Company ? $company : null;
    }

    public function forget(): void
    {
        $this->companyId = null;
        $this->company = null;
    }

    /**
     * Scope the rest of this request to no company at all.
     *
     * For an account that genuinely belongs to nobody yet — a shipper who has
     * registered and not yet picked a haulier. They can read the carrier
     * directory, which is not scoped because it *is* the companies, and they
     * can file the request that opens them an account and ends this state.
     * Everything else they could ask for is somebody else's, and answers
     * empty.
     *
     * Deliberately not `forget()`. Nothing in force means no filtering at all,
     * which in a request is not "no data" but *all* data — the one failure this
     * system cannot have.
     */
    public function nobody(): void
    {
        $this->set(self::NOBODY);
    }

    /** True when this request is scoped to the company that does not exist. */
    public function isNobody(): bool
    {
        return $this->companyId === self::NOBODY;
    }

    /**
     * Run a closure as one company, then put back whatever was in force.
     *
     * The restore is in a `finally` so a closure that throws still leaves the
     * tenant where it found it. A command that fails part-way through company
     * three must not carry company three into company four.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function use(Company|string|null $company, Closure $callback): mixed
    {
        $previousId = $this->companyId;
        $previousCompany = $this->company;

        $this->set($company);

        try {
            return $callback();
        } finally {
            $this->companyId = $previousId;
            $this->company = $previousCompany;
        }
    }

    /**
     * Run a closure across every company at once.
     *
     * Only correct for platform work — the scheduled sweeps, a report over the
     * whole install, a migration. Never for anything reached from a request:
     * this is precisely the switch that turns tenant isolation off.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function across(Closure $callback): mixed
    {
        return $this->use(null, $callback);
    }
}
