<?php

declare(strict_types=1);

namespace App\Domain\Hr\Services;

use App\Domain\Driver\Models\Driver;
use App\Domain\Hr\DTO\EmployeeData;
use App\Domain\Hr\DTO\LicenceData;
use App\Domain\Hr\Models\Employee;
use App\Domain\Hr\Repositories\EmployeeRepository;
use App\Domain\Identity\Models\Position;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Repositories\Repository;
use App\Domain\Shared\Services\CrudService;
use Illuminate\Http\UploadedFile;

/**
 * The roster.
 *
 * Registration is the one verb here that is not plain CRUD, because it has a
 * photograph attached and a payroll number to allocate — and the number has to
 * come from the same place every time or two people end up sharing one.
 *
 * It also decides whether the person needs a `drivers` row, which is the second
 * thing that stops this being CRUD. The office no longer picks one from a list;
 * they type a licence, and `linkDriverRecord()` works out whether that means an
 * existing record or a new one.
 */
class EmployeeService extends CrudService
{
    public function __construct(
        private readonly EmployeeRepository $employees,
        private readonly PhotoStore $photos,
    ) {}

    protected function repository(): Repository
    {
        return $this->employees;
    }

    /**
     * Register somebody, with their photograph and — if they drive — a licence.
     *
     * The employee number is allocated here when the caller offered none, so
     * the office never has to know the numbering scheme — and cannot collide
     * with a number already on a payslip.
     */
    public function register(EmployeeData $data, ?UploadedFile $photo, ?LicenceData $licence = null): Employee
    {
        $attributes = $data->persistable();

        if (empty($attributes['employee_no'])) {
            $attributes['employee_no'] = $this->employees->nextEmployeeNo();
        }

        $attributes['photo_path'] = $this->photos->store($photo, 'employees');
        $attributes = $this->withPositionLabel($attributes);

        $employee = Employee::create($attributes)->refresh();

        $this->linkDriverRecord($employee, $licence);

        return $employee->refresh();
    }

    /**
     * Keep the free-text title in step with the chosen job.
     *
     * The `position` column stays the label everything else reads — the roster
     * table, the performance figures, the search. Denormalising it means a
     * position renamed later leaves old records saying what they said at the
     * time, which for a job title is the honest answer rather than a bug: it is
     * what that person was called then.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withPositionLabel(array $attributes): array
    {
        if (empty($attributes['position_id'])) {
            return $attributes;
        }

        $position = Position::find($attributes['position_id']);

        if ($position !== null) {
            $attributes['position'] = $position->name;
        }

        return $attributes;
    }

    /**
     * Edit a record, replacing the photograph only when a new one arrived.
     *
     * Absent means "not part of this edit", which is the same rule the DTOs
     * follow. Reading a missing file as "remove the photograph" would clear it
     * on every form submission that did not re-upload one.
     */
    public function edit(
        Employee $employee,
        EmployeeData $data,
        ?UploadedFile $photo,
        ?LicenceData $licence = null,
    ): Employee {
        $attributes = $data->persistable();

        if ($photo !== null) {
            $attributes['photo_path'] = $this->photos->replace($employee->photo_path, $photo, 'employees');
        }

        $employee->update($this->withPositionLabel($attributes));

        // The name on a login is the person's, so a correction to the roster
        // has to reach it. Without this, fixing a misspelled surname leaves the
        // old one on every screen that greets them by name.
        if ($employee->user !== null && ($data->first_name !== null || $data->last_name !== null)) {
            $employee->user->forceFill(['name' => $employee->fresh()->fullName()])->save();
        }

        // After the update, so moving somebody *into* a driving job opens their
        // driver record in the same save rather than needing a second edit.
        $this->linkDriverRecord($employee->refresh(), $licence);

        return $employee->refresh();
    }

    /**
     * Give a driving employee their `drivers` row, or find the one they have.
     *
     * This is what replaced the **Driver record** dropdown, and the reason it
     * can is that a licence number identifies a driver better than a name in a
     * list does. Three cases, and the middle one is why matching beats picking:
     *
     *   **Already linked** — the row is theirs; the licence details are written
     *   through to it, because a renewal is exactly what somebody is doing when
     *   they edit a driver's expiry date on the roster.
     *
     *   **A record exists under that licence** — the fleet knew this driver
     *   before HR did, which is the normal order in a business that was running
     *   before it had an HR module. They are linked, and the operational record
     *   is left standing: every trip, dispatch and GPS ping in the system points
     *   at it, and the whole point of `employees` is to describe that person,
     *   not to replace their history.
     *
     *   **Nobody on file** — a new hire. The row is opened here so registering
     *   a driver is one form rather than two screens in a particular order.
     *
     * Nothing happens for a job that does not drive, and nothing happens when
     * the submission carried no licence — an edit that corrects a phone number
     * must leave the driver record exactly as it was.
     *
     * **A driver moved off the road keeps their record.** There is no branch
     * here that unlinks or deletes one, and there should not be: the history
     * belongs to the person, and taking it away because their job title changed
     * would quietly rewrite who drove which trip.
     */
    private function linkDriverRecord(Employee $employee, ?LicenceData $licence): void
    {
        if ($licence === null || ! $licence->hasLicence()) {
            return;
        }

        // The job decides, not the caller. A licence sent for an office role is
        // ignored rather than obeyed — otherwise a stray field on a payload
        // would put the bookkeeper on the driver roster.
        if ($employee->jobPosition?->drives() !== true) {
            return;
        }

        $details = array_filter([
            'licence_no' => $licence->licence_no,
            'licence_expiry' => $licence->licence_expiry,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        if ($employee->driver !== null) {
            $employee->driver->update($details);

            return;
        }

        $existing = Driver::query()->where('licence_no', $licence->licence_no)->first();

        $driver = $existing ?? Driver::create([
            ...$details,
            'name' => $employee->fullName(),
            // Available, because somebody just hired is somebody who can be
            // given a run. `Driver::create` would default this anyway; saying
            // it here is what makes that a decision rather than an accident.
            'status' => StatusValue::Available->value,
        ]);

        if ($existing !== null) {
            $existing->update($details);
        }

        $employee->update(['driver_id' => $driver->id]);
    }

    /**
     * The roster headline: how many people, doing what.
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $byStatus = $this->employees->countsByStatus();
        $byPosition = $this->employees->countsByPosition();

        arsort($byPosition);

        return [
            'headcount' => array_sum($byStatus),
            'active' => $byStatus['active'] ?? 0,
            'inactive' => $byStatus['inactive'] ?? 0,
            'by_position' => array_map(
                static fn (string $position, int $count): array => [
                    'position' => $position,
                    'count' => $count,
                ],
                array_keys($byPosition),
                array_values($byPosition),
            ),
            'without_account' => $this->employees->all(['has_account' => false])->count(),
        ];
    }

    public function photoUrl(?string $path): ?string
    {
        return $this->photos->url($path);
    }
}
