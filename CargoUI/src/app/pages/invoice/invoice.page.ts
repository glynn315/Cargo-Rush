import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';

import { Invoice } from '../../models/billing/billing.model';
import { InvoiceDocument } from '../../models/billing/invoice-document.model';
import { invoiceSpec } from '../../services/billing/billing.form';
import { BillingService } from '../../services/billing/billing.service';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { PaymentDialog } from '../../shared/payment-dialog';
import { RecordDialog } from '../../shared/record-dialog';
import { ErrorState } from '../../shared/states';
import { StatusPill } from '../../shared/status-pill';
import { StatusValue } from '../../models/shared/status.model';

/**
 * The invoice as a document — the copy that leaves the building.
 *
 * Its own route rather than a modal, for two reasons that are really one. A
 * document is a *place*: `/billing/INV-2026-0041` is a link somebody can send
 * to a colleague, keep in a tab, or come back to. And it has to print — which
 * means it needs a page of its own that the print stylesheet can strip the
 * shell off, rather than a dialog floating over a sidebar.
 *
 * ## Printing is the export
 *
 * There is no PDF library here and there does not need to be one. Every browser
 * prints to PDF, the dialog it opens is the one the user already knows, and it
 * produces a file that says "Save as PDF" in their own language. A server-side
 * renderer would be a second layout to keep in step with this one, and the
 * first time they diverged the customer would have a copy the office cannot
 * reproduce.
 *
 * What that costs is control over the page break, and `invoice.page.html` pays
 * it in a handful of print rules — the shell hidden, the ink forced black, the
 * totals block kept off a break.
 *
 * ## Why the payload is assembled by the API
 *
 * Everything on this page is one call. The issuer's registered name and TIN,
 * the customer's, the haul, the tax lines, the amount in words and every
 * payment against the invoice come back together, because a document rendered
 * from four requests can be rendered half-built — and a half-built invoice is
 * one somebody prints and sends.
 */
@Component({
  selector: 'app-invoice',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon, ErrorState, StatusPill],
  templateUrl: './invoice.page.html',
})
export class InvoicePage {
  private readonly billing = inject(BillingService);
  private readonly router = inject(Router);
  private readonly dialog = inject(RecordDialog);
  private readonly payments = inject(PaymentDialog);

  /**
   * The same form the list edits with.
   *
   * Editing lives here rather than on the list, and the row action opens this
   * page instead: an invoice is looked at far more often than it is changed,
   * and when it *is* changed the figures being changed are on screen. One spec,
   * shared, so the two places cannot drift into asking for different fields.
   */
  private readonly spec = invoiceSpec();

  private readonly route = inject(ActivatedRoute);

  /**
   * The invoice id, off the route.
   *
   * From the snapshot rather than the observable, and rather than a bound
   * component input: this route is only ever entered fresh, and
   * `withComponentInputBinding` is not on for the app — turning it on for one
   * page would change how every other route hands over its parameters.
   */
  private readonly invoiceId = this.route.snapshot.paramMap.get('invoice') ?? '';

  protected readonly fmt = fmt;

  /** Null means still loading — the same three states as every other read. */
  protected readonly document = signal<InvoiceDocument | null>(null);
  protected readonly error = signal<string | null>(null);

  constructor() {
    // Read once, on the id the route arrived with. A document does not poll:
    // what it says is what was true when it was printed, and a figure that
    // changed under somebody's cursor mid-print would be worse than stale.
    queueMicrotask(() => this.load());

    // Re-read after an edit. The dialog answers with the saved invoice, but a
    // document is more than the invoice row — the tax lines move, the balance
    // moves — so the whole thing is fetched again rather than patched.
    this.dialog
      .savedFor(this.spec)
      .pipe(takeUntilDestroyed())
      .subscribe(() => this.load());

    // And after a payment, which changes the balance, the status and the list
    // of payments printed at the foot of the document.
    this.payments.recorded.pipe(takeUntilDestroyed()).subscribe(() => this.load());
  }

  /** Open the edit form for this invoice, with the document behind it. */
  protected edit(): void {
    this.billing.find(this.invoiceId).subscribe({
      next: (invoice: Invoice) => this.dialog.edit(this.spec, invoice),
      error: () => this.error.set('Could not open that invoice for editing.'),
    });
  }

  /**
   * Record money against this document — how it becomes paid.
   *
   * On this page rather than only on the list, because this is the screen
   * somebody is looking at when a customer rings to say they have paid: the
   * balance, the reference and the haul are all in front of them. The dialog
   * opens with the balance filled in, which is what is owed after any
   * withholding and anything already received.
   */
  protected recordPayment(): void {
    this.billing.find(this.invoiceId).subscribe({
      next: (invoice: Invoice) => this.payments.forInvoice(invoice),
      error: () => this.error.set('Could not open that invoice.'),
    });
  }

  protected load(): void {
    const id = this.invoiceId;

    if (id === '') {
      this.error.set('No invoice was named.');

      return;
    }

    this.billing.document(id).subscribe({
      next: (document) => {
        this.document.set(document);
        this.error.set(null);
      },
      error: () => {
        this.document.set(null);
        this.error.set('Could not load that invoice. It may have been deleted.');
      },
    });
  }

  /**
   * The status pill's value.
   *
   * Straight from the API's own vocabulary — an invoice already carries one of
   * the shared statuses, so there is nothing to map.
   */
  protected readonly status = computed<StatusValue>(
    () => (this.document()?.document.status ?? 'pending') as StatusValue,
  );

  /** VAT and withholding as percentages, from the basis points on the record. */
  protected readonly vatRate = computed(() => (this.document()?.totals.vat_rate_bp ?? 0) / 100);

  protected readonly withholdingRate = computed(
    () => (this.document()?.totals.withholding_rate_bp ?? 0) / 100,
  );

  /**
   * The company's initials, for a haulier that has not uploaded a mark.
   *
   * The same fallback the shell's user chip makes, rather than a broken image
   * or a hole where the logo goes — a printed invoice with a gap at the top
   * looks like a fault in the system that produced it.
   */
  protected readonly initials = computed(() => {
    const name = this.document()?.issuer.name ?? '';

    return name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((word) => word[0]?.toUpperCase() ?? '')
      .join('');
  });

  /** Hand the page to the browser's print dialog — which is also its PDF. */
  protected print(): void {
    window.print();
  }

  protected back(): void {
    this.router.navigate(['/billing']);
  }
}
