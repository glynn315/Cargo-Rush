import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import {
  PayPeriodOption,
  PayRun,
  PayRunLinePayload,
  PayRunPayload,
} from '../../models/hr/payroll.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';

/** A run list narrows by state and by the period it covers. */
export interface PayrollQuery extends ListQuery {
  status?: string;
  from?: string;
  to?: string;
}

/**
 * Payroll — building a run, correcting it, freezing it, paying it.
 *
 * Four verbs, and every one of them is a verb rather than a status PATCH, for
 * the reason posting a journal entry is: **approve** freezes figures and tells
 * the office, and **pay** writes the journal entry that puts the run in the
 * books. A status field that did either when set to a particular value would
 * hide what actually happened.
 *
 * `rebuild` is a POST rather than a PUT because it is not an edit: it throws
 * the lines away and works them out again from the employee records as they now
 * stand. Merging would leave somebody paid from two different calculations.
 */
@Injectable({ providedIn: 'root' })
export class PayrollService {
  private readonly api = inject(ApiService);

  list(query?: PayrollQuery): Observable<Envelope<PayRun[]>> {
    return this.api.envelope<PayRun[]>('payroll', query);
  }

  /**
   * The pay periods a month is allowed to have — the choice on screen.
   *
   * Payroll is cut off on the 1st and the 16th, and the API owns that rule.
   * With no month it answers with the month holding the period that has just
   * closed and flags it, so the page opens on the run somebody has come to pay
   * without doing any calendar arithmetic of its own.
   */
  periods(month?: string): Observable<Envelope<PayPeriodOption[]>> {
    return this.api.envelope<PayPeriodOption[]>(
      'payroll/periods',
      month === undefined || month === '' ? undefined : { month },
    );
  }

  find(id: string): Observable<PayRun> {
    return this.api.get<PayRun>(`payroll/${id}`);
  }

  /** Open a run for a period and work everybody's pay out. */
  create(payload: PayRunPayload): Observable<PayRun> {
    return this.api.post<PayRun>('payroll', payload);
  }

  /** Work it out again, from the employee records as they now stand. */
  rebuild(id: string): Observable<PayRun> {
    return this.api.post<PayRun>(`payroll/${id}/rebuild`, {});
  }

  /** Correct one payslip. The whole run comes back, so the totals follow. */
  adjustLine(id: string, lineId: string, payload: PayRunLinePayload): Observable<PayRun> {
    return this.api.patch<PayRun>(`payroll/${id}/lines/${lineId}`, payload);
  }

  /** Freeze it. Payslips can go out from here, so nothing may move after. */
  approve(id: string): Observable<PayRun> {
    return this.api.post<PayRun>(`payroll/${id}/approve`, {});
  }

  /** The money has gone out — and this is what posts it to the books. */
  pay(id: string): Observable<PayRun> {
    return this.api.post<PayRun>(`payroll/${id}/pay`, {});
  }

  /** Delete a draft. An approved run has been shown to people. */
  remove(id: string): Observable<void> {
    return this.api.delete(`payroll/${id}`);
  }
}
