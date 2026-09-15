import { inject } from '@angular/core';

import { Employee } from '../../models/hr/hr.model';
import { RecordSpec, statusOptions, toFormData } from '../../shared/record-form-spec';
import { AccessService } from '../identity/access.service';
import { EmployeeService } from './employee.service';

/**
 * Employee registration — the record, including the photograph.
 *
 * This is the one spec that sends `FormData` rather than JSON, because of the
 * upload. `toFormData` drops anything the person left blank, which matters
 * more here than it looks: in multipart every value is a string, so an
 * untouched optional field would arrive as `""` and overwrite a real value
 * with a blank on the next edit.
 *
 * The employee number is not a field. It is allocated by the API when the
 * office has none to give, and a number that has been on a payslip is never
 * reissued — which is not something a form control can promise.
 *
 * **The driver details are separate, and conditional.** There used to be a
 * *Driver record* dropdown here listing every driver on file. It asked
 * somebody hiring a mechanic to choose from a fleet of drivers for no reason,
 * and somebody hiring an actual driver to choose a record that does not exist
 * yet — so registering a driver meant creating half a person in Drivers
 * Management first and coming back. It is now a licence number and an expiry,
 * shown only when the chosen job drives, and the API finds or opens the
 * `drivers` row from the licence.
 */
export function employeeSpec(): RecordSpec<Employee> {
  const employees = inject(EmployeeService);
  const access = inject(AccessService);

  const jobs: { value: string; label: string }[] = [];

  /**
   * The positions that need a licence, by id.
   *
   * Held as a set rather than re-derived per keystroke, and filled from the
   * API's own `drives` flag rather than guessed from the job's name — "Long-haul
   * Driver" and "Yard Marshal" are not a pattern a client can match, and the
   * server is the one that will accept or refuse the save.
   */
  const driving = new Set<string>();

  // Active only: a retired position is one the office has stopped hiring for,
  // and offering it would quietly put new people back into it.
  access.positions(true).subscribe((res) => {
    jobs.length = 0;
    jobs.push(...res.data.map((p) => ({ value: p.id, label: p.name })));

    driving.clear();
    for (const position of res.data) {
      if (position.drives) driving.add(position.id);
    }
  });

  /** Does the job currently chosen on the form need a licence? */
  const jobDrives = (values: Record<string, unknown>): boolean =>
    driving.has(String(values['position_id'] ?? ''));

  return {
    noun: 'employee',
    icon: 'badge',

    fields: [
      { key: 'first_name', label: 'First name', kind: 'text', required: true },
      { key: 'last_name', label: 'Last name', kind: 'text', required: true },
      { key: 'middle_name', label: 'Middle name', kind: 'text' },
      {
        key: 'position_id',
        label: 'Position',
        kind: 'select',
        options: () => jobs,
        hint: 'Manage the list in Access Control.',
      },
      {
        key: 'position',
        label: 'Custom title',
        kind: 'text',
        placeholder: 'Night Warehouse Supervisor',
        hint: 'Only if the list has no name for it.',
      },
      { key: 'department', label: 'Department', kind: 'text', placeholder: 'Operations' },
      {
        key: 'employment_type',
        label: 'Employment',
        kind: 'select',
        options: () => [
          { value: 'probationary', label: 'Probationary' },
          { value: 'regular', label: 'Regular' },
          { value: 'contractual', label: 'Contractual' },
          { value: 'part_time', label: 'Part-time' },
        ],
      },
      { key: 'hired_on', label: 'Hired on', kind: 'date', required: true },
      { key: 'birth_date', label: 'Date of birth', kind: 'date' },
      { key: 'contact', label: 'Contact number', kind: 'text', required: true },
      {
        key: 'email',
        label: 'Email',
        kind: 'text',
        hint: 'Their own address, not the login.',
      },
      { key: 'address', label: 'Address', kind: 'text', wide: true },
      { key: 'emergency_contact', label: 'Emergency contact', kind: 'text' },
      { key: 'emergency_phone', label: 'Emergency number', kind: 'text' },
      { key: 'base_salary', label: 'Base salary (₱)', kind: 'money' },

      /**
       * The driver details. On screen only when the job drives.
       *
       * Required there, and the API says the same — this is the client half of
       * one rule, not a second rule. A licence typed against an office job is
       * dropped rather than obeyed at the other end, so the two cannot disagree
       * about who ends up on the driver roster.
       */
      {
        key: 'licence_no',
        label: 'Licence number',
        kind: 'text',
        required: true,
        placeholder: 'N01-23-456789',
        hint: 'Exactly as printed. Matches an existing driver record if there is one.',
        showWhen: jobDrives,
      },
      {
        key: 'licence_expiry',
        label: 'Licence expiry',
        kind: 'date',
        required: true,
        hint: 'The system warns you 90 days out.',
        showWhen: jobDrives,
      },

      {
        key: 'photo',
        label: 'Photograph',
        kind: 'file',
        accept: 'image/*',
        hint: 'Blank keeps the photo on file.',
      },
      {
        key: 'status',
        label: 'Status',
        kind: 'select',
        options: statusOptions(['active', 'inactive']),
      },
      { key: 'notes', label: 'Notes', kind: 'textarea', wide: true },
    ],

    title: (record) => `${record.full_name} · ${record.employee_no}`,

    toForm: (record) => ({
      first_name: record.first_name,
      last_name: record.last_name,
      middle_name: record.middle_name ?? '',
      position_id: record.position_id ?? '',
      // Left blank when the title came from the list — the API copies the
      // label across, so resending it would only be a chance to disagree.
      position: record.position_id ? '' : record.position,
      department: record.department ?? '',
      employment_type: record.employment_type,
      hired_on: record.hired_on,
      birth_date: record.birth_date ?? '',
      contact: record.contact,
      email: record.email ?? '',
      address: record.address ?? '',
      emergency_contact: record.emergency_contact ?? '',
      emergency_phone: record.emergency_phone ?? '',
      base_salary: record.base_salary_cents / 100,
      // Read back off the driver record, so reopening somebody shows the
      // licence on file rather than an empty box that looks like it was never
      // entered. Blank for everybody who does not drive — the fields are not
      // on screen for them anyway.
      licence_no: record.licence_no ?? '',
      licence_expiry: record.licence_expiry ?? '',
      status: record.status,
      notes: record.notes ?? '',
      // Never prefilled: a file input cannot be given a value, and the API
      // reads a missing photo as "leave the one on file alone".
      photo: null,
    }),

    toPayload: (values) => ({
      ...values,
      base_salary_cents: Math.round(Number(values['base_salary'] ?? 0) * 100),
      base_salary: undefined,
    }),

    save: (payload, id) => {
      const values = payload as Record<string, unknown>;

      return employees.save(toFormData(values, id ? 'PATCH' : undefined), id);
    },

    remove: (id) => employees.remove(id),
  };
}
