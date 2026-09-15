<?php

declare(strict_types=1);

namespace App\Domain\Hr\Requests;

use App\Domain\Driver\Models\Driver;
use App\Domain\Hr\DTO\EmployeeData;
use App\Domain\Hr\DTO\LicenceData;
use App\Domain\Hr\Models\Employee;
use App\Domain\Identity\Models\Position;
use App\Domain\Shared\Enums\EmploymentType;
use App\Domain\Shared\Enums\StatusValue;
use App\Domain\Shared\Http\Requests\ApiFormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Registering somebody, and — only if they drive — their licence.
 *
 * The form used to carry a **Driver record** dropdown: pick, from a list of
 * every driver already on file, the one this employee is. It was wrong in both
 * directions. Somebody being hired as a mechanic was shown a fleet of drivers
 * to choose from for no reason, and somebody being hired *as a driver* had no
 * record to pick, because they are new — so registering a driver meant going to
 * Drivers Management first, creating half a person there, and coming back.
 *
 * So the driver details are separated out and asked for only when the job needs
 * them, which the position itself answers (`Position::drives()`). Two fields, a
 * licence number and its expiry, and the service does the rest: it finds the
 * `drivers` row that licence already belongs to, or opens one.
 *
 * Matching on the licence number is what replaces the dropdown. It is unique
 * within a company and it is what a driver is actually filed under — so the
 * person entering it does not have to know whether an operational record
 * already exists, which is the one thing about this they had no way to know.
 */
class EmployeeRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $required = $this->requiredOnCreate();
        $employee = $this->employee();

        return [
            // Optional: allocated by the service when the office has none to
            // give, which is the normal case.
            'employee_no' => [
                'sometimes', 'string', 'max:30',
                Rule::unique('employees', 'employee_no')->ignore($employee?->id)->whereNull('deleted_at'),
            ],
            'first_name' => [$required, 'string', 'max:60'],
            'last_name' => [$required, 'string', 'max:60'],
            'middle_name' => ['nullable', 'string', 'max:60'],
            /**
             * Either a title from the managed list or one typed in.
             *
             * On a create one of the two is required; on a PATCH neither is,
             * so an edit that resends only a corrected surname does not demand
             * the person be reclassified as well.
             */
            'position' => [
                $this->creating() ? 'required_without:position_id' : 'sometimes',
                'string',
                'max:60',
            ],
            'position_id' => ['nullable', 'string', 'exists:positions,id'],
            'department' => ['nullable', 'string', 'max:60'],
            'employment_type' => ['sometimes', Rule::in(EmploymentType::values())],
            'status' => ['sometimes', Rule::in([StatusValue::Active->value, StatusValue::Inactive->value])],
            'hired_on' => [$required, 'date'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'contact' => [$required, 'string', 'max:40'],
            // Not unique and not required. Plenty of staff have no address of
            // their own, and it is not the login — creating an account is a
            // separate action that validates the address it is given.
            'email' => ['nullable', 'email', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'emergency_contact' => ['nullable', 'string', 'max:80'],
            'emergency_phone' => ['nullable', 'string', 'max:40'],
            'base_salary_cents' => ['sometimes', 'integer', 'min:0'],

            /**
             * The licence, required exactly when the job drives.
             *
             * `required` rather than `required_if:...`, because what decides it
             * is a row in another table — whether the chosen position's default
             * role is the driver's — and no declarative rule can read that. It
             * is worked out once in `licenceRequired()` and both fields follow
             * it, so they cannot end up demanding different things.
             */
            'licence_no' => [$this->licenceRequired() ? 'required' : 'nullable', 'string', 'max:40'],
            'licence_expiry' => [$this->licenceRequired() ? 'required' : 'nullable', 'date'],

            'notes' => ['nullable', 'string', 'max:255'],
            'photo' => [
                'nullable', 'image',
                'max:'.(int) config('cargo.hr.photo_max_kb'),
            ],
        ];
    }

    /**
     * One driver record, one employee.
     *
     * The old form enforced this with `Rule::unique` on the `driver_id` the
     * client sent. There is no such field any more, so the same rule is now
     * asked of the licence: if that number is already on the roster under
     * somebody else, this is either a typo or two people being registered as
     * the same driver, and both want stopping here rather than at the database.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $licence = $this->string('licence_no')->trim()->value();

                if ($licence === '' || $validator->errors()->has('licence_no')) {
                    return;
                }

                $driverId = Driver::query()->where('licence_no', $licence)->value('id');

                if ($driverId === null) {
                    return;
                }

                $heldBy = Employee::query()
                    ->where('driver_id', $driverId)
                    ->whereKeyNot($this->employee()?->id)
                    ->value('employee_no');

                if ($heldBy !== null) {
                    $validator->errors()->add(
                        'licence_no',
                        "That licence is already on employee {$heldBy}'s record.",
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'licence_no.required' => 'A driver needs their licence number.',
            'licence_expiry.required' => 'A driver needs their licence expiry date.',
            'base_salary_cents.integer' => 'Send the salary in centavos as a whole number, not pesos.',
            'photo.max' => 'That photograph is too large. An ID photo, not a portrait session.',
        ];
    }

    public function toData(): EmployeeData
    {
        // The photograph is `PhotoStore`'s and the licence is the `drivers`
        // row's; neither is an `employees` column, so neither belongs in a DTO
        // that is handed straight to `Employee::create()`.
        return EmployeeData::fromArray(
            collect($this->validated())->except(['photo', 'licence_no', 'licence_expiry'])->all()
        );
    }

    /**
     * The licence, or null when this submission said nothing about one.
     *
     * Null and "blank" are kept apart deliberately: an edit that resends only a
     * corrected surname must leave the driver record alone, and an empty
     * `LicenceData` would read as an instruction to clear it.
     */
    public function toLicence(): ?LicenceData
    {
        $licence = collect($this->validated())->only(['licence_no', 'licence_expiry'])->all();

        return $licence === [] ? null : LicenceData::fromArray($licence);
    }

    public function photo(): ?UploadedFile
    {
        $photo = $this->file('photo');

        return $photo instanceof UploadedFile ? $photo : null;
    }

    /**
     * Does this submission have to carry a licence?
     *
     * Yes when the job it lands in drives *and* there is no driver record
     * behind the person already. The second half is what keeps an edit
     * workable: correcting a surname on a driver who is already on the fleet
     * must not demand their licence be retyped to save it.
     */
    private function licenceRequired(): bool
    {
        return $this->jobPosition()?->drives() === true
            && $this->employee()?->driver_id === null;
    }

    /**
     * The managed position this submission lands in.
     *
     * Falls back to the one the employee already holds, because a PATCH that
     * only corrects a phone number does not resend the position — and reading
     * that silence as "no position" would let somebody edit a driver into
     * having no licence requirement by not mentioning their job.
     *
     * A typed-in **Custom title** has no position row, so it answers null and
     * asks for no licence. That is honest rather than clever: only the managed
     * list knows which jobs drive, and guessing from the words somebody typed
     * would create a driver record for a "Driveway Attendant".
     */
    private function jobPosition(): ?Position
    {
        $positionId = $this->input('position_id') ?: $this->employee()?->position_id;

        return $positionId === null ? null : Position::query()->find($positionId);
    }

    private function employee(): ?Employee
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee ? $employee : null;
    }
}
