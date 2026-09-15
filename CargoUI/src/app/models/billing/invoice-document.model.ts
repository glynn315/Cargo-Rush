/**
 * `GET /api/v1/billing/{invoice}/document` — the invoice as a document.
 *
 * Not the same shape as `Invoice`, and deliberately: that is a row in a list,
 * and this is a piece of paper. It carries the things a screen can leave
 * implicit and a document cannot — who is billing and their TIN, who is being
 * billed and theirs, what haul it is for, and every payment against it.
 *
 * Assembled server-side from the invoice, the company, the customer and the
 * trip. A client that stitched those together would be four round trips and a
 * second place deciding what an invoice must contain.
 *
 * Money is integer centavos, as everywhere else. `amount_in_words` is the one
 * pre-rendered string in the API, and it is not formatting: an amount written
 * out cannot be altered with a pen, which is why printed invoices have carried
 * one for a century.
 */

/** The haulier issuing the document. */
export interface InvoiceIssuer {
  name: string | null;
  code: string | null;
  /** Printed beside the name — a Philippine invoice shows both parties' TINs. */
  tin: string | null;
  vat_registered: boolean;
  address: string | null;
  contact_name: string | null;
  contact_phone: string | null;
  contact_email: string | null;
  /** Null means print the company's initials, not a gap. */
  logo_url: string | null;
}

/** Who is being billed. */
export interface InvoiceBillTo {
  name: string;
  customer_id: string | null;
  address: string | null;
  contact: string | null;
  tin: string | null;
  vat_treatment: string | null;
  vat_treatment_label: string | null;
  /** True when this customer withholds — which is why `due` is short of `total`. */
  withholds_tax: boolean;
}

export interface InvoiceDocumentHead {
  id: string;
  number: string;
  /**
   * What to print at the top: `Delivery Invoice` when a haul raised it,
   * `Sales Invoice` for one raised by hand, `Bill` for a payable.
   */
  title: string;
  direction: string;
  issued_at: string | null;
  due_at: string | null;
  /** Worked out from the two dates. Null when either is missing. */
  terms_days: number | null;
  status: string;
  overdue: boolean;
  currency: string;
}

/** One line of the invoice — for freight, the haul itself. */
export interface InvoiceDocumentLine {
  description: string;
  reference: string | null;
  origin: string | null;
  destination: string | null;
  cargo: string | null;
  weight_kg: number | null;
  delivered_at: string | null;
  driver: string | null;
  plate: string | null;
  /** The net, because VAT is shown once at the bottom rather than per line. */
  amount_cents: number;
}

export interface InvoiceDocumentTotals {
  net_amount_cents: number;
  vat_cents: number;
  /** What the document says: net plus VAT. */
  amount_cents: number;
  withholding_cents: number;
  /** What the bank should receive — the total less what the payer withholds. */
  due_cents: number;
  paid_cents: number;
  balance_cents: number;
  vat_rate_bp: number | null;
  withholding_rate_bp: number | null;
  vat_treatment: string | null;
  vat_label: string | null;
  /** The due figure, written out, as an invoice carries it. */
  amount_in_words: string;
}

/** One payment, as much of it as landed on this invoice. */
export interface InvoiceDocumentPayment {
  id: string | null;
  paid_on: string | null;
  method: string | null;
  method_label: string | null;
  reference: string | null;
  notes: string | null;
  recorded_by: string | null;
  /** The allocated part, not the whole payment — one cheque can settle three. */
  amount_cents: number;
}

export interface InvoiceDocument {
  issuer: InvoiceIssuer;
  bill_to: InvoiceBillTo;
  document: InvoiceDocumentHead;
  lines: InvoiceDocumentLine[];
  totals: InvoiceDocumentTotals;
  payments: InvoiceDocumentPayment[];
}
