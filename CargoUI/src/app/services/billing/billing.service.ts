import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import {
  Aging,
  BillingTotals,
  Invoice,
  InvoicePayload,
  Payment,
  PaymentPayload,
} from '../../models/billing/billing.model';
import { InvoiceDocument } from '../../models/billing/invoice-document.model';
import { StatementOfAccount } from '../../models/billing/statement-of-account.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';
import { environment } from '../../../environments/environment';

/** Billing & Invoice — receivables, payables, payment history. */
@Injectable({ providedIn: 'root' })
export class BillingService {
  private readonly api = inject(ApiService);

  list(query?: ListQuery): Observable<Envelope<Invoice[]>> {
    return this.api.envelope<Invoice[]>('billing', query);
  }

  find(id: string): Observable<Invoice> {
    return this.api.get<Invoice>(`billing/${id}`);
  }

  create(payload: InvoicePayload): Observable<Invoice> {
    return this.api.post<Invoice>('billing', payload);
  }

  update(id: string, payload: Partial<InvoicePayload>): Observable<Invoice> {
    return this.api.patch<Invoice>(`billing/${id}`, payload);
  }

  remove(id: string): Observable<void> {
    return this.api.delete(`billing/${id}`);
  }

  /**
   * Marking one paid, as a verb rather than a status patch.
   *
   * It settles the **balance**, which is the gross less any withholding tax
   * and less anything already received — so an invoice to a customer who
   * withholds closes on the figure that actually arrives, and a part-paid one
   * closes on what is left. A payment is recorded behind it; the status is
   * derived from that rather than set.
   */
  settle(id: string, details: Partial<PaymentPayload> = {}): Observable<Invoice> {
    return this.api.post<Invoice>(`billing/${id}/settle`, details);
  }

  /**
   * The invoice as a document — everything a printable copy has to carry.
   *
   * Its own call rather than more fields on `find()`: the list and the edit
   * form have no use for the issuer's TIN or the driver's name, and a document
   * is the one read that needs all of it at once.
   */
  document(id: string): Observable<InvoiceDocument> {
    return this.api.get<InvoiceDocument>(`billing/${id}/document`);
  }

  /**
   * Where the browser should go to download the list as a spreadsheet.
   *
   * A URL rather than a request, and that is the point: the file is served as
   * an attachment, so it has to be fetched by the browser itself for the
   * download to happen — an `HttpClient` call would land the CSV in memory
   * with nothing to do with it. The session cookie rides along because it is
   * the same first-party origin the rest of the app authenticates with
   * (DESIGN.md section 7.4).
   *
   * The filters go with it, because the export somebody wants is the list they
   * are looking at.
   */
  exportUrl(query: ListQuery = {}): string {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(query)) {
      if (value === null || value === undefined || value === '') continue;

      params.set(key, String(value));
    }

    const search = params.toString();

    return `${environment.apiUrl}/api/v1/billing/export${search === '' ? '' : '?' + search}`;
  }

  totals(): Observable<BillingTotals> {
    return this.api.get<BillingTotals>('billing/totals');
  }

  /** What is outstanding, by how late it is. */
  aging(direction: 'receivable' | 'payable' = 'receivable'): Observable<Aging> {
    return this.api.get<Aging>('billing/aging', { direction });
  }

  /**
   * One firm's running account — the collections document.
   *
   * Takes a *customer* rather than an invoice, because a statement is about the
   * account and not about one document on it. `direction: 'payable'` turns it
   * into a supplier statement: the same report read the other way round, since
   * one party's receivable is the other's payable.
   */
  statement(
    customerId: string,
    query: { from?: string; to?: string; direction?: 'receivable' | 'payable' } = {},
  ): Observable<StatementOfAccount> {
    return this.api.get<StatementOfAccount>(`billing/statement/${customerId}`, query);
  }

  /* --------------------------------------------------------- Payments */

  payments(query?: ListQuery): Observable<Envelope<Payment[]>> {
    return this.api.envelope<Payment[]>('payments', query);
  }

  /**
   * Record money that has moved.
   *
   * `allocations` may name several invoices — one cheque settling a month —
   * or none at all, which is money on account before there is a document to
   * put it against.
   */
  recordPayment(payload: PaymentPayload): Observable<Payment> {
    return this.api.post<Payment>('payments', payload);
  }

  removePayment(id: string): Observable<void> {
    return this.api.delete(`payments/${id}`);
  }
}
