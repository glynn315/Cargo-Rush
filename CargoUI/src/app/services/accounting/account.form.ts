import { inject } from '@angular/core';

import { Account } from '../../models/accounting/accounting.model';
import { RecordSpec, statusOptions } from '../../shared/record-form-spec';
import { AccountingService } from './accounting.service';

/**
 * One line of the chart of accounts.
 *
 * A flat record, so it uses the shared form rather than one of its own — the
 * journal entry is the thing in this module that needed a component, because
 * an entry is a head and a variable set of sides.
 *
 * The `type` hint is the one piece of teaching on the form, and it earns its
 * place: the type decides which side increases the account, which statement it
 * lands on and which column of a trial balance it appears in. It is also the
 * one field the API will refuse to change once anything has been posted, which
 * is worth knowing *before* saving rather than afterwards.
 */
export function accountSpec(): RecordSpec<Account> {
  const accounting = inject(AccountingService);

  /**
   * The five types, from the API's own enum.
   *
   * Fetched rather than written out here for the reason the statuses are: the
   * list belongs to the server, and a client with its own copy offers an option
   * the validator refuses the first time one changes.
   */
  const types: { value: string; label: string }[] = [];

  accounting.accountTypes().subscribe((rows) => {
    types.length = 0;
    types.push(...rows.map((t) => ({ value: t.value, label: t.label })));
  });

  return {
    noun: 'account',
    icon: 'tag',

    fields: [
      {
        key: 'code',
        label: 'Number',
        kind: 'text',
        required: true,
        placeholder: '5065',
        hint: '1000s assets, 2000s liabilities, 3000s equity, 4000s income, 5000s expenses.',
      },
      { key: 'name', label: 'Name', kind: 'text', required: true, placeholder: 'Batteries' },
      {
        key: 'type',
        label: 'Kind',
        kind: 'select',
        required: true,
        options: () => types,
        hint: 'Decides which way its balance runs. Fixed once anything is posted to it.',
      },
      {
        key: 'group',
        label: 'Group',
        kind: 'text',
        placeholder: 'Cost of services',
        hint: 'A sub-heading on the statements. Yours to name.',
      },
      {
        key: 'status',
        label: 'Status',
        kind: 'select',
        options: statusOptions(['active', 'inactive']),
        hint: 'Retired accounts keep their history and take no new postings.',
      },
      { key: 'description', label: 'Note', kind: 'textarea', wide: true },
    ],

    title: (record) => record.label,

    toForm: (record) => ({
      code: record.code,
      name: record.name,
      type: record.type,
      group: record.group ?? '',
      status: record.status,
      description: record.description ?? '',
    }),

    toPayload: (values) => ({
      code: String(values['code'] ?? '').trim(),
      name: values['name'],
      type: values['type'],
      group: values['group'] || null,
      status: values['status'] || 'active',
      description: values['description'] || null,
    }),

    save: (payload, id) =>
      id
        ? accounting.updateAccount(id, payload as never)
        : accounting.createAccount(payload as never),

    /**
     * No `remove`.
     *
     * Deleting an account is not a row action here, and that is deliberate: the
     * API answers a delete two different ways — gone, or retired with its
     * history kept — and a table row that vanishes on some presses and greys on
     * others with no explanation is worse than an explicit choice. The page
     * offers retiring through the status field on the form, which says what it
     * does.
     */
  };
}
