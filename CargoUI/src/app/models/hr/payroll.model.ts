import { Timestamped } from '../shared/envelope.model';

/**
 * Payroll: a period's pay, frozen, handed out, and put in the books.
 *
 * Money is integer centavos, like everywhere else. Two things about the shape
 * are worth knowing before reading it.
 *
 * **A run always arrives with its lines.** A run without them is a period and a
 * total, which is not something anybody can check — and the screen that shows a
 * run is the screen somebody checks it on before approving it.
 *
 * **The statutory figures are kept apart.** SSS, PhilHealth, Pag-IBIG and the
 * withholding tax are four separate columns rather than one deduction, because
 * each is remitted to its own agency on its own form. A single total would turn
 * the monthly remittance into a spreadsheet exercise.
 */

/** draft → approved → paid. Nothing goes backwards. */
export type PayRunStatus = 'draft' | 'approved' | 'paid';

/** What each agency is owed out of a run, plus what the firm recovered. */
export interface PayRunStatutory {
  sss: number;
  philhealth: number;
  pagibig: number;
  withholding_tax: number;
  /** Advances and anything else the firm held back. Not an agency's. */
  other: number;
}

/**
 * One person's payslip.
 *
 * The name and the position are copied onto the line rather than read through
 * the employee: a payslip is a statement about a fortnight and has to keep
 * saying what it said after somebody is promoted or leaves.
 */
export interface PayRunLine {
  id: string;
  employee_id: string;
  employee_no: string | null;
  name: string;
  position: string | null;

  /** The monthly basic split across the runs in a month. */
  basic_cents: number;
  allowance_cents: number;
  overtime_cents: number;
  /** Signed: a bonus and a docked half-day are both real. */
  adjustments_cents: number;
  adjustment_note: string | null;

  sss_cents: number;
  philhealth_cents: number;
  pagibig_cents: number;
  withholding_tax_cents: number;
  other_deductions_cents: number;
  deduction_note: string | null;

  /** Stored, because these three are what was printed on the paper. */
  gross_cents: number;
  deductions_cents: number;
  net_cents: number;
}

export interface PayRun extends Timestamped {
  id: string;
  /** `PR-2026-0004`, from the API's own series. Never chosen by a client. */
  reference: string;

  period_start: string;
  period_end: string;
  /** `1–15 Sep 2026` — how a period reads on a list. */
  period_label: string;
  /** When the money goes out, which is the date the journal entry carries. */
  pay_date: string;

  status: PayRunStatus;
  approved_at: string | null;
  approved_by_name: string | null;
  paid_at: string | null;

  /**
   * The entry this run posted, once it was paid.
   *
   * Null before that, and it is the link that says payroll reached the books
   * rather than stopping at a spreadsheet.
   */
  journal_entry_id: string | null;
  journal_reference: string | null;

  /** True for the 1st-to-15th payslip. */
  is_first_cutoff: boolean;
  /**
   * Which cutoff the firm takes the monthly contributions on, and what that
   * means in words.
   *
   * This is what makes a ₱0.00 SSS line legible: a payslip with no
   * contributions on it is either correct — because the firm takes them all on
   * the other cutoff — or a mistake, and a reader cannot tell which without
   * being told the policy. It arrives with the run so the figures and the
   * explanation of them cannot get out of step.
   */
  deduct_on: 'split' | 'first' | 'second';
  deduct_on_label: string;
  deduct_on_detail: string;
  /** Does this cutoff carry the funds at all? */
  carries_contributions: boolean;

  staff_count: number;
  gross_cents: number;
  deductions_cents: number;
  net_cents: number;
  statutory: PayRunStatutory;
  currency: string;

  /**
   * What this page may offer.
   *
   * From the API, because the rules are the API's: a draft can be rebuilt,
   * edited, approved and deleted; an approved run can only be paid; a paid one
   * is finished. Two clients working that out from `status` is two places to
   * get it wrong.
   */
  can_edit: boolean;
  can_approve: boolean;
  can_pay: boolean;

  notes: string | null;

  lines: PayRunLine[];
}

/**
 * One of the pay periods a month is allowed to have.
 *
 * Payroll is cut off on the 1st and the 16th, so a period is the 1st to the
 * 15th or the 16th to the end of the month — never a range somebody types. The
 * API works these out and this app renders them: the same reasoning as the
 * account types and the journal categories, and it means a client cannot get
 * February wrong or keep offering two halves to an office that has switched to
 * paying monthly.
 */
export interface PayPeriodOption {
  /** `first`, `second`, or `month` where payroll runs once. */
  half: 'first' | 'second' | 'month';
  start: string;
  end: string;
  /** `1–15 Sep 2026`. */
  label: string;
  /** `1–15` — for a choice where the month is already on screen. */
  short: string;
  /** The day the period closes and payroll is run: the 16th, or the 1st. */
  cutoff: string;
  /** Days in the period, counting both ends. Thirteen in a short February. */
  days: number;
  /**
   * The period that has just closed — the one an office has come to pay.
   *
   * Sent rather than worked out here, because "which period is due" is a
   * question about the calendar and the pay schedule, and both live on the
   * server.
   */
  suggested: boolean;
}

/** Opening a run: the period it covers, and the day the money goes out. */
export interface PayRunPayload {
  period_start: string;
  period_end: string;
  pay_date: string;
}

/**
 * A correction to one payslip.
 *
 * Every field optional, because this is a correction and not a rewrite. Note
 * what is *not* recomputed when a gross moves: the withholding tax. An
 * allowance is usually a one-off that the BIR table would tax as if it were the
 * person's regular pay, and an office correcting a payslip has not asked for
 * the tax to move under them. A run that needs the tax redone is rebuilt.
 */
export interface PayRunLinePayload {
  allowance_cents?: number;
  overtime_cents?: number;
  adjustments_cents?: number;
  adjustment_note?: string | null;
  sss_cents?: number;
  philhealth_cents?: number;
  pagibig_cents?: number;
  withholding_tax_cents?: number;
  other_deductions_cents?: number;
  deduction_note?: string | null;
}
