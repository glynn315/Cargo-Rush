<?php

declare(strict_types=1);

namespace App\Domain\Driver\Controllers;

use App\Domain\Driver\Models\Driver;
use App\Domain\Driver\Requests\DriverRequest;
use App\Domain\Driver\Resources\DriverResource;
use App\Domain\Driver\Services\DriverService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Drivers Management — LTMS violations, licences, driver status. */
class DriverController extends ApiController
{
    public function __construct(private readonly DriverService $drivers) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->drivers->paginate($this->filters($request), $this->perPage($request));

        return $this->collection(DriverResource::collection($page), $page);
    }

    public function show(Driver $driver): JsonResponse
    {
        return $this->item(new DriverResource($driver));
    }

    public function store(DriverRequest $request): JsonResponse
    {
        return $this->item(new DriverResource($this->drivers->create($request->toData())), status: 201);
    }

    public function update(DriverRequest $request, Driver $driver): JsonResponse
    {
        return $this->item(new DriverResource($this->drivers->update($driver, $request->toData())));
    }

    public function destroy(Driver $driver): JsonResponse
    {
        $this->drivers->delete($driver);

        return $this->noContent();
    }

    /**
     * The office setting somebody else's availability.
     *
     * Behind `drivers.manage` with the rest of the roster: taking a named
     * driver off the board is a rostering decision, and the person making it is
     * not the person it is about.
     */
    public function availability(Request $request, Driver $driver): JsonResponse
    {
        $validated = $request->validate(['available' => ['required', 'boolean']]);

        return $this->item(new DriverResource(
            $this->drivers->setAvailability($driver, $validated['available'])
        ));
    }

    /**
     * The availability switch on the driver app's dashboard.
     *
     * A driver saying whether they are free is not a rostering decision about
     * somebody, it is somebody answering for themselves — so it takes no driver
     * id, like every other call the handset makes about its own work, and needs
     * no `drivers.manage`. That permission is how the office edits the roster,
     * and no driver holds it: the switch on the dashboard answered 403 for
     * every driver who ever touched it, which is what this exists to fix.
     *
     * There is nothing left to gate. The only row it can reach is the caller's
     * own, resolved from the token, and an account with no driver record gets a
     * 404 — the same answer `DriverTripController` gives an administrator
     * asking for "my trips". A permission on top of that would be one an office
     * could revoke to break a driver's own switch, which is not a setting
     * anybody wants.
     */
    public function ownAvailability(Request $request): JsonResponse
    {
        $validated = $request->validate(['available' => ['required', 'boolean']]);

        $driver = $this->drivers->forUser($request->user()->id);

        abort_if($driver === null, 404, 'This account is not linked to a driver record.');

        return $this->item(new DriverResource(
            $this->drivers->setAvailability($driver, $validated['available'])
        ));
    }
}
