<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Requests;

use App\Domain\Shared\Enums\DeductionSchedule;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * What a company may change about itself.
 *
 * A short list, and the omissions are the design. The **name** is on every
 * invoice already issued, the **code** is what makes every other company's
 * uniqueness work, and the **status** is the platform's answer about a firm
 * rather than the firm's about itself. None of the three is a field a form
 * fills in, so none of them is here — rather than being here and quietly
 * dropped by the service.
 *
 * `sometimes` throughout, because this is a PATCH: moving a map pin should not
 * require resending a phone number, and a client that sent only the pin must
 * not blank the rest by omission.
 */
class CompanyProfileRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:160'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'address' => ['sometimes', 'nullable', 'string', 'max:200'],

            /**
             * Where the yard is.
             *
             * A pair or nothing, the same rule both ends of a trip follow: half
             * a coordinate is not a place, and a latitude stored without its
             * longitude would put the company on the carrier list at a point
             * off the coast of Africa.
             *
             * Both nullable together, which is how a firm takes itself off the
             * list — sending nulls is the only way to stop being discoverable,
             * and it is a deliberate act rather than a flag to find.
             */
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],

            /**
             * Which cutoff the monthly contributions come off.
             *
             * A policy rather than a rate, which is why it is settable at all:
             * the SSS percentage is the government's and lives in
             * configuration, but whether a firm loads a month of contributions
             * onto the first payslip or the second is the firm's own decision.
             * See `DeductionSchedule`.
             */
            'payroll_deduct_on' => ['sometimes', Rule::enum(DeductionSchedule::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'latitude.required_with' => 'A longitude needs its latitude.',
            'longitude.required_with' => 'A latitude needs its longitude.',
            'payroll_deduct_on' => 'Contributions come off both cutoffs, the first, or the second.',
        ];
    }

    /**
     * The fields to write, coordinates as numbers.
     *
     * Named `toAttributes` and not `attributes`: the latter is Laravel's own
     * hook for the display names in a validation message, and overriding it
     * with a payload would rename every field in every error this form raises.
     *
     * Cast here rather than left as the strings a query string or a JSON
     * document can carry, so what reaches the column is a number and what comes
     * back out of the cast is the same number.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        $attributes = $this->safe()->only([
            'contact_name', 'contact_email', 'contact_phone', 'address', 'latitude', 'longitude',
            'payroll_deduct_on',
        ]);

        foreach (['latitude', 'longitude'] as $key) {
            if (array_key_exists($key, $attributes) && $attributes[$key] !== null) {
                $attributes[$key] = (float) $attributes[$key];
            }
        }

        return $attributes;
    }
}
