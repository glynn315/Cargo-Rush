<?php

declare(strict_types=1);

namespace App\Domain\Incident\Controllers;

use App\Domain\Driver\Models\Driver;
use App\Domain\Driver\Services\DriverService;
use App\Domain\Incident\Requests\ReportIncidentRequest;
use App\Domain\Incident\Resources\IncidentResource;
use App\Domain\Incident\Services\IncidentService;
use App\Domain\Shared\Http\Controllers\ApiController;
use App\Domain\Trip\Services\TripService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The driver's own incidents — what `cargoApp` reports from the road.
 *
 * Separate from `IncidentController` for the reason `DriverTripController` is
 * separate from `TripController`: everything here is scoped to the caller and
 * carries no id, so a driver cannot write against somebody else's run or read
 * somebody else's write-up by changing a value in a payload. The office reads
 * and manages the same rows through the module controller, with the permissions
 * that come with it.
 *
 * ## Why a driver could not do this before
 *
 * Raising an incident was `POST incidents`, behind `incidents.manage` — the
 * office's own permission, which no driver holds. So the one person who is
 * actually present when something goes wrong had to ring the desk and have
 * somebody else type it. `incidents.write` is the driver's half, named to match
 * `delivery.write` and `inspection.write`: report what happened to you, on your
 * own run. Reading the log, editing a write-up and closing one out stay with
 * the office.
 *
 * The report attaches the trip and the unit by itself. A driver knows what
 * happened and where; which trip reference and which plate were involved is
 * something the system already knows and should not be asking a person standing
 * on a hard shoulder to confirm.
 */
class DriverIncidentController extends ApiController
{
    public function __construct(
        private readonly IncidentService $incidents,
        private readonly DriverService $drivers,
        private readonly TripService $trips,
    ) {}

    /**
     * `GET incidents/mine` — what this driver has reported, newest first.
     *
     * Here so the app can show that a report landed and is being dealt with.
     * A driver who reports a blowout and then sees nothing has no way to tell
     * whether the office got it, and the next thing they do is ring the desk to
     * ask — which is the phone call this feature exists to save.
     *
     * Their own rows only, and the filter is not the caller's to change:
     * `incidents.view` is the office's permission for the whole log.
     */
    public function index(Request $request): JsonResponse
    {
        $incidents = $this->incidents->forDriver($this->driver($request)->id);

        return $this->collection(IncidentResource::collection($incidents), $incidents);
    }

    /**
     * `POST incidents/report` — something has gone wrong.
     *
     * Lands as `pending`, which is the column's own default and the honest
     * status for a report nobody at the desk has read yet, and puts a row in
     * the notification feed on the way — see `IncidentService::report()`. The
     * driver is told the reference, because a reference is what the conversation
     * that follows will be about.
     */
    public function store(ReportIncidentRequest $request): JsonResponse
    {
        $driver = $this->driver($request);

        // Whatever run they are on, if any. An incident does not need a trip —
        // a unit can break down in the yard on the way to a pickup — so a
        // driver between runs reports one just the same and the office sees it
        // against them and their unit rather than against a delivery.
        $trip = $this->trips->currentForDriver($driver->id);

        $incident = $this->incidents->report($request->toData(
            driverId: $driver->id,
            // The unit on the run, and the one they hold the keys to otherwise.
            // Either way it is the truck that was actually there.
            vehicleId: $trip?->vehicle_id ?? $driver->vehicle?->id,
            tripId: $trip?->id,
        ));

        return $this->item(new IncidentResource($incident), status: 201);
    }

    /**
     * The driver record behind the login. A back-office user calling these has
     * no driver row, and a 404 says that plainly rather than filing an incident
     * against nobody.
     */
    private function driver(Request $request): Driver
    {
        $driver = $this->drivers->forUser($request->user()->id);

        abort_if($driver === null, 404, 'This account is not linked to a driver record.');

        return $driver;
    }
}
