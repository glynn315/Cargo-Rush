<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Controllers;

use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Tenancy\Requests\CompanyLogoRequest;
use App\Domain\Tenancy\Requests\CompanyProfileRequest;
use App\Domain\Tenancy\Resources\CompanyResource;
use App\Domain\Tenancy\Services\CompanyService;
use Illuminate\Http\JsonResponse;

/**
 * The caller's own company.
 *
 * **No id in any path.** These sit alongside the driver and customer endpoints
 * in that respect, and for a sharper version of the same reason: an id a client
 * can send is an id a client can change, and the one thing worse than reading
 * another firm's trips is overwriting their identity. The company comes off the
 * account, as it does everywhere else.
 */
class CompanyController extends ApiController
{
    public function __construct(private readonly CompanyService $company) {}

    public function show(): JsonResponse
    {
        return $this->item(new CompanyResource($this->company->current()));
    }

    /**
     * `PATCH company` — the firm's own details, and where its yard is.
     *
     * The pin is what this is for. It is asked for at registration, and this is
     * how it is moved afterwards — and the only way a haulier that signed up
     * before the carrier list existed can appear on it at all.
     *
     * Answers with the whole company, as the logo endpoints do, so a client
     * redraws from what was stored rather than from what it sent.
     */
    public function update(CompanyProfileRequest $request): JsonResponse
    {
        return $this->item(new CompanyResource($this->company->update($request->toAttributes())));
    }

    /**
     * `POST company/logo` — multipart, one field.
     *
     * A POST rather than a PATCH on the company: the upload replaces the file
     * outright, and there is no partial version of an image. It answers with
     * the whole company so the client can render the new `logo_url` without a
     * second call — and because the URL is derived, not stored, the client
     * could not have worked it out for itself.
     */
    public function storeLogo(CompanyLogoRequest $request): JsonResponse
    {
        return $this->item(new CompanyResource($this->company->setLogo($request->logo())));
    }

    /**
     * `DELETE company/logo`.
     *
     * Answers with the company rather than 204, unlike most destroys here. The
     * shell renders the logo, so what the client needs back is the state it
     * should now draw — which is a company whose `logo_url` is null and whose
     * initials it falls back to.
     */
    public function destroyLogo(): JsonResponse
    {
        return $this->item(new CompanyResource($this->company->clearLogo()));
    }
}
