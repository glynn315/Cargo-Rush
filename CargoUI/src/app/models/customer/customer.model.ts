import { StatusValue } from '../shared/status.model';
import { Timestamped } from '../shared/envelope.model';
import { VatTreatment } from '../billing/billing.model';

/** Customer Management — records, transaction history, feedback. */
export interface Customer extends Timestamped {
  id: string;
  name: string;
  contact: string;
  /**
   * Where this firm's loads leave from, and its pin.
   *
   * Null for a customer the desk typed in — the office rings them about a
   * pickup and has no field for it. Filled in for a firm that signed itself up
   * in the app and pinned its store, which is the only thing the desk knows
   * about a customer who arrived without a phone call. The address is editable
   * here; the pin belongs to the customer, who set it in their own app.
   */
  address: string | null;
  latitude: number | null;
  longitude: number | null;
  /** Aggregates the API derives; never entered. */
  trips_total: number;
  outstanding_cents: number;
  currency: string;
  rating: number;
  status: StatusValue;
  /** What the firm signs in with. Null for a customer with no portal account. */
  login_email: string | null;
  /**
   * The starting password, on the response that just created the account and
   * nowhere else. The one chance the office has to read it and pass it on — a
   * customer read back later has null here.
   */
  default_password: string | null;

  /**
   * How this firm is taxed.
   *
   * Read by every invoice raised for them, by hand or by a delivery, so it is
   * set once here rather than per document. Both are properties of who is
   * being billed rather than of what was hauled.
   *
   * `withholds_tax` is the one with a surprise in it: a customer who withholds
   * pays **less than the invoice says**, on purpose, and remits the difference
   * to the BIR. Marking them correctly is what stops that reading as a short
   * payment.
   */
  tin: string | null;
  vat_treatment: VatTreatment;
  vat_label: string;
  withholds_tax: boolean;
  /** Null means the statutory rate applies. */
  withholding_rate_bp: number | null;
}

export interface CustomerPayload {
  name: string;
  contact: string;
  /**
   * Where the loads leave from.
   *
   * The coordinates are deliberately not on the payload: they are the
   * customer's own pin, set in their app, and an office form that sent them
   * back as blanks on every edit would quietly wipe it.
   */
  address?: string | null;
  rating?: number;
  status?: StatusValue;
  tin?: string | null;
  vat_treatment?: VatTreatment;
  withholds_tax?: boolean;
  /**
   * The address to give the firm a portal login at. Omitted rather than
   * blanked when there is none: sending it empty is not a request for an
   * account, and the API only ever adds one, never takes one away.
   */
  email?: string;
}
