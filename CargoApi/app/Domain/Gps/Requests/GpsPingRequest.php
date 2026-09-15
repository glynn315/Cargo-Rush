<?php

declare(strict_types=1);

namespace App\Domain\Gps\Requests;

use App\Domain\Gps\DTO\GpsPingData;
use App\Domain\Shared\Http\Requests\ApiFormRequest;

/**
 * A position report from the cab.
 *
 * The coordinates are `nullable` and that is deliberate rather than lax. A
 * handset still running the build that posted only a formatted `location`
 * string is a truck on the road, and refusing its reports until somebody
 * updates an app would be trading real visibility for tidy data. What it gets
 * instead is a report the dashboard can show and the map cannot draw — which
 * is what every report in this system was until the coordinates existed.
 *
 * They are `required_with` each other, though: half a coordinate is not a
 * degraded position, it is a bug, and storing a latitude with no longitude
 * would put a truck on the Greenwich meridian.
 */
class GpsPingRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'trip_id' => ['required', 'string', 'exists:trips,id'],
            // Still required: it is what a person reads on the dashboard, and
            // where there is no place name the handset formats the pair into it.
            'location' => ['required', 'string', 'max:160'],
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
            'speed_kph' => ['required', 'integer', 'min:0', 'max:200'],
            'heading' => ['sometimes', 'string', 'max:16'],
            'progress_pct' => ['required', 'integer', 'min:0', 'max:100'],
            'distance_done_m' => ['sometimes', 'integer', 'min:0'],
            // The handset stamps this, because it may have been offline when
            // the reading was taken and is only posting it now.
            'recorded_at' => ['required', 'date', 'before_or_equal:now'],
        ];
    }

    public function toData(): GpsPingData
    {
        return GpsPingData::fromArray($this->validated());
    }
}
