import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';

/** `receivable` is money in, `payable` is money out. */
export type InvoiceDirection = 'receivable' | 'payable';

/** Billing & Invoice — DESIGN.md section 5.1. */
export interface Invoice extends Timestamped {
  id: string;
  number: string;
  /** Whoever the document is addressed to, whichever way it points. */
  customer: string;
  customer_id: string | null;
  payee: string | null;
  issued_at: string;
  due_at: string;

  /**
   * The five figures a Philippine freight invoice carries.
   *
   *     net + vat = amount, and amount - withholding = due
   *
   * `amount_cents` keeps its old meaning — what the document says — so nothing
   * that read it before tax existed has to change. `due_cents` is the one that
   * matters at the bank: a customer who withholds pays less than the document
   * asks, deliberately, and the difference goes to the BIR rather than being
   * owed to us. Reading that gap as a shortfall is the mistake this removes.
   */
  net_amount_cents: number;
  vat_cents: number;
  amount_cents: number;
  withholding_cents: number;
  due_cents: number;

  /**
   * What the tax was worked out from — and the only figure a form may edit.
   *
   * The net where the rate card excludes VAT, the gross where it includes it.
   * A *write's* `amount_cents` means this, while a read's means net plus VAT:
   * one name, two meanings, and a form that round-tripped the read added the
   * VAT again on every save — ₱7,530 billed as ₱8,433.60, then ₱9,445.63.
   */
  taxable_base_cents: number;

  /** Summed from the payments, never stored. */
  paid_cents: number;
  balance_cents: number;

  /** Frozen on the day, so a document can be reprinted exactly as issued. */
  vat_rate_bp: number;
  withholding_rate_bp: number;
  vat_treatment: VatTreatment | null;
  vat_label: string | null;

  currency: string;
  direction: InvoiceDirection;
  status: StatusValue;
  /** The haul this document is for, when a delivery raised it. */
  trip_id: string | null;
  trip_reference: string | null;
  /** When the money arrived. Null until it is settled. */
  paid_at: string | null;
}

/**
 * How VAT applies to a customer.
 *
 * Three rather than a boolean: zero-rated and exempt both put ₱0 on the
 * invoice and mean different things to the filing.
 */
export type VatTreatment = 'vatable' | 'zero_rated' | 'exempt';

/**
 * Money that moved, as a record of its own.
 *
 * Separate from the invoice because it has its own date and reference and may
 * settle several documents — none of which fits in a status column.
 */
export interface Payment extends Timestamped {
  id: string;
  customer_id: string | null;
  customer_name: string | null;
  direction: InvoiceDirection;
  amount_cents: number;
  currency: string;
  /** The day the money moved, not the day it was typed in. */
  paid_on: string;
  method: string;
  /** What a bank statement is matched against. */
  reference: string | null;
  notes: string | null;
  /** A payment bigger than the invoices it covers leaves a credit. */
  allocated_cents: number;
  unallocated_cents: number;
  allocations?: { invoice_id: string; invoice_number: string | null; amount_cents: number }[];
  recorded_by: string | null;
}

export interface PaymentPayload {
  customer_id?: string | null;
  direction?: InvoiceDirection;
  amount_cents: number;
  paid_on: string;
  method?: string;
  reference?: string | null;
  notes?: string | null;
  /** May be empty: money on account, before there is a document for it. */
  allocations?: { invoice_id: string; amount_cents: number }[];
}

/**
 * What is outstanding, by how late it is.
 *
 * The report a collections call is made from. Counted from the **due** date
 * and at each invoice's **balance** — a document half-paid is half a problem.
 */
export interface AgingBuckets {
  current: number;
  '1_30': number;
  '31_60': number;
  '61_90': number;
  over_90: number;
}

export interface Aging {
  buckets: AgingBuckets;
  total_cents: number;
  /** Worst first: whoever owes the most is the call to make. */
  by_counterparty: (AgingBuckets & { counterparty: string; total_cents: number })[];
  currency: string;
}

export interface InvoicePayload {
  number: string;
  customer_id?: string | null;
  payee?: string | null;
  issued_at: string;
  due_at: string;
  /**
   * The **taxable base** — the figure the desk has, before VAT.
   *
   * Not the document total, despite sharing its name with the read. Send
   * `Invoice.taxable_base_cents` back, never `Invoice.amount_cents`.
   */
  amount_cents: number;
  currency?: string;
  direction: InvoiceDirection;
  /**
   * Where the document stands — `pending`, `overdue` or `cancelled`.
   *
   * Not `paid` or `partial`: those follow from the payments recorded against
   * an invoice, and the API refuses to take them here. Settle it, or record
   * the payment.
   */
  status?: StatusValue;
}

/** Receivables against payables — the two numbers the page leads with. */
export interface BillingTotals {
  receivable_cents: number;
  payable_cents: number;
  /** Positive means the business is owed more than it owes. */
  net_position_cents: number;
  /** Money in, as against money merely billed. */
  collected_cents: number;
  currency: string;
}
