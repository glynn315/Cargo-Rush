<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Tenant;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * The company's own record, as the company sees it.
 *
 * Everything here acts on **the caller's own company and only that one**. There
 * is no id parameter on any method, and there is none on any of the routes
 * either — the same reasoning as the driver and customer endpoints, which are
 * scoped to the token rather than taking an id. A company id in a path is an id
 * somebody can change, and this is the one record where doing so would rewrite
 * another firm's identity.
 */
class CompanyService
{
    public function __construct(
        private readonly Tenant $tenant,
        private readonly LogoStore $logos,
    ) {}

    /** The company in force. */
    public function current(): Company
    {
        $company = $this->tenant->company();

        // Unreachable through the API — `BindTenant` turns away an account with
        // no company before any controller runs. Stated rather than assumed,
        // because a null slipping this far would surface as a mangled response
        // instead of a fault.
        if ($company === null) {
            throw new RuntimeException('No company in force. This should have been caught by BindTenant.');
        }

        return $company;
    }

    /**
     * Change the company's own details.
     *
     * Only what a firm may say about itself: how to reach it, and where its
     * yard is. Not the name — that is on the paperwork of every invoice already
     * issued — not the code, which other companies' uniqueness depends on, and
     * not the status, which is the platform's answer about them rather than
     * theirs about themselves.
     *
     * The pin is the field this exists for. A haulier that registered before
     * the carrier list existed is invisible to shippers until it drops one, and
     * it would otherwise have no way to.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(array $attributes): Company
    {
        $company = $this->current();

        $company->update($attributes);

        return $company->refresh();
    }

    /**
     * Set or replace the logo.
     *
     * The old file is removed as part of this rather than left behind: a logo
     * is re-uploaded every time somebody does not like how it sits in the
     * sidebar, and the discarded attempts have nothing pointing at them.
     */
    public function setLogo(UploadedFile $file): Company
    {
        $company = $this->current();

        $company->update([
            'logo_path' => $this->logos->replace($company->logo_path, $file),
        ]);

        return $company->refresh();
    }

    /**
     * Take the logo away, and the file with it.
     *
     * Clearing the column alone would leave the file on disk reachable by
     * anybody who had noted the URL — which for a logo is harmless, and is
     * still not what "remove" means.
     */
    public function clearLogo(): Company
    {
        $company = $this->current();
        $existing = $company->logo_path;

        $company->update(['logo_path' => null]);
        $this->logos->remove($existing);

        return $company->refresh();
    }
}
