<?php

declare(strict_types=1);

namespace App\Domain\Incident\Requests;

use App\Domain\Incident\DTO\IncidentData;
use App\Domain\Shared\Http\Requests\ApiFormRequest;

/**
 * A driver reporting something that has gone wrong, from where it went wrong.
 *
 * The counterpart of `IncidentRequest`, which is the office's form. That one
 * can name anybody: it is how a dispatcher writes up a call they have just
 * taken, so it carries a driver, a vehicle and a trip. This one names nobody,
 * and the omissions are the security property rather than a shorter form —
 * `driver_id`, `vehicle_id` and `trip_id` are the *scope*, stamped from the
 * token by the controller. A driver filing an incident against another driver's
 * run is the one thing a form like this must not allow, and the way to
 * guarantee it is to have nowhere to put the id.
 *
 * `status` is absent for a different reason: an incident's status is the
 * office's answer to it. Reported means `pending`, which the column already
 * defaults to, and a driver who could set it to resolved could close their own
 * incident before anybody had read it.
 *
 * Three fields, because three is what somebody standing on a hard shoulder can
 * answer: what happened, where, and anything worth adding. Everything else is
 * either known already or is the desk's to work out.
 */
class ReportIncidentRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            /**
             * What happened, in the driver's words.
             *
             * Free text, exactly as the office form has it — "Tyre blowout",
             * "Traffic hold", "Cargo damage". The app offers the usual ones as
             * chips so nobody types on a roadside, but the list is a
             * convenience and not a vocabulary: the moment it became an enum,
             * the thing that actually happened would have to be squeezed into
             * whichever option was closest.
             */
            'kind' => ['required', 'string', 'max:120'],

            'place' => ['required', 'string', 'max:160'],

            /**
             * When it happened. Optional, and it usually is.
             *
             * A driver reporting from the scene means *now*, and asking a
             * person holding a phone in the rain to confirm the time would be a
             * field that only ever gets one answer. It is here for the other
             * case: a report written up at the depot afterwards, where the time
             * that matters is when the truck was there and not when somebody
             * got round to typing it.
             */
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],

            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'kind.required' => 'Say what happened — a few words is enough.',
            'place.required' => 'Say where it happened.',
            'occurred_at.before_or_equal' => 'An incident cannot be reported for a future time.',
        ];
    }

    /**
     * The incident, as the driver described it and with the driver attached.
     *
     * The three ids come from the caller rather than the payload. `occurred_at`
     * falls back to now, here rather than in the rules, so that "when it
     * happened" has exactly one default and the validator is not the place that
     * decides it.
     */
    public function toData(string $driverId, ?string $vehicleId, ?string $tripId): IncidentData
    {
        $validated = $this->validated();

        return IncidentData::fromArray([
            ...$validated,
            'occurred_at' => $this->date('occurred_at')?->toIso8601String() ?? now()->toIso8601String(),
            'driver_id' => $driverId,
            'vehicle_id' => $vehicleId,
            'trip_id' => $tripId,
        ]);
    }
}
