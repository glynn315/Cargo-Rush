import { inject } from '@angular/core';

import { Invoice } from '../../models/billing/billing.model';
import { RecordSpec, statusOptions } from '../../shared/record-form-spec';
import { CustomerService } from '../customer/customer.service';
import { BillingService } from './billing.service';

/**
 * Billing & Invoice.
 *
 * A receivable names the customer being billed; a payable names who is being
 * paid. Both fields are offered and the API enforces which one is required
 * for the direction chosen — an unaddressed document is not a document.
 */
export function invoiceSpec(): RecordSpec<Invoice> {
  const billing = inject(BillingService);
  const customers = inject(CustomerService);

  const accounts: { value: string; label: string }[] = [];

  customers.list().subscribe((res) => {
    accounts.length = 0;
    accounts.push(...res.data.map((c) => ({ value: c.id, label: c.name })));
  });

  return {
    noun: 'invoice',
    icon: 'billing',

    fields: [
      // No Number field: the API assigns it — `INV-{year}-####` for a
      // receivable, `BILL-{year}-####` for a payable — and strips anything
      // sent. A box whose value is silently discarded is worse than no box,
      // and two people filling this form at once would otherwise race for
      // the same number and one would be rejected by the unique index.
      {
        key: 'direction',
        label: 'Direction',
        kind: 'select',
        required: true,
        options: () => [
          { value: 'receivable', label: 'Receivable — money in' },
          { value: 'payable', label: 'Payable — money out' },
        ],
      },
      {
        key: 'customer_id',
        label: 'Customer',
        kind: 'select',
        options: () => accounts,
        hint: 'Required for a receivable.',
      },
      {
        key: 'payee',
        label: 'Payee',
        kind: 'text',
        placeholder: 'Petron Fleet Card',
        hint: 'Required for a payable.',
      },
      /**
       * The taxable figure, not the document total.
       *
       * What the desk has: the net haul, or the all-in price where the rate
       * card is quoted VAT-inclusive. The API adds the VAT and the withholding
       * and reports the total back — so this field must never be filled from
       * `amount_cents`, which already has the VAT in it. It was, and every save
       * put 12% on top of 12%.
       */
      { key: 'amount', label: 'Amount before VAT (₱)', kind: 'money', required: true },
      { key: 'issued_at', label: 'Issued', kind: 'date', required: true },
      {
        key: 'due_at',
        label: 'Due',
        kind: 'date',
        required: true,
        hint: 'Cannot precede the issue date.',
      },
      {
        /**
         * Where the document stands — and `paid` is not on the list.
         *
         * Paid and part-paid follow from the payments recorded against an
         * invoice, so the API refuses them here. Offering the option meant a
         * document could be flagged settled with nothing behind it, which is
         * exactly what told one customer ₱21,482 had been collected while the
         * bank had seen none of it. Settling is its own action, and it leaves
         * the payment behind it.
         */
        key: 'status',
        label: 'Status',
        kind: 'select',
        options: statusOptions(['pending', 'overdue', 'cancelled']),
        hint: 'Paid follows from a payment — settle it or record one.',
      },
    ],

    title: (invoice) => `${invoice.number} · ${invoice.customer}`,

    toForm: (invoice) => ({
      direction: invoice.direction,
      customer_id: invoice.customer_id ?? '',
      payee: invoice.payee ?? '',
      // `taxable_base_cents`, not `amount_cents`: the base the tax was worked
      // out from, so re-saving a document leaves its figures alone. See
      // `Invoice::taxBaseCents()` on the API.
      amount: invoice.taxable_base_cents / 100,
      issued_at: invoice.issued_at,
      due_at: invoice.due_at,
      status: invoice.status,
    }),

    toPayload: (values) => ({
      direction: values['direction'],
      customer_id: values['customer_id'] || null,
      payee: values['payee'] || null,
      amount_cents: Math.round(Number(values['amount'] ?? 0) * 100),
      currency: 'PHP',
      issued_at: values['issued_at'],
      due_at: values['due_at'],
      status: values['status'] || 'pending',
    }),

    save: (payload, id) =>
      id ? billing.update(id, payload as never) : billing.create(payload as never),

    remove: (id) => billing.remove(id),
  };
}
