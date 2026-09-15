import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';

import { BalanceSheet, IncomeStatement } from '../../models/accounting/statement.model';
import { Aging, InvoiceDirection } from '../../models/billing/billing.model';
import { StatementOfAccount } from '../../models/billing/statement-of-account.model';
import { Customer } from '../../models/customer/customer.model';
import { AccountingService } from '../../services/accounting/accounting.service';
import { BillingService } from '../../services/billing/billing.service';
import { CustomerService } from '../../services/customer/customer.service';
import { Card } from '../../shared/card';
import { Field } from '../../shared/field';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { EmptyState, ErrorState } from '../../shared/states';

/** Which statement is on screen. */
type View = 'income' | 'sheet' | 'aging' | 'account';

/**
 * Financial Statements — what the books mean, as against what they contain.
 *
 * The general journal is the record and the general ledger is that record
 * sorted by account. Neither answers the two questions somebody actually asks
 * of a set of books: *did we make money*, and *what are we worth*. This page is
 * those two, plus the two collections documents that hang off them.
 *
 * ## Four views, which are four different questions
 *
 * **The income statement** — revenue, the cost of hauling, everything else, and
 * the two margins. A period, and it means it: income and expenses start again
 * each period, so the range is the statement rather than a filter on it.
 *
 * **The balance sheet** — what is owned against what is owed and what the
 * owners have, at a single date. A date rather than a range, because assets
 * carry over; "the balance sheet for March" means the sheet as it stood on the
 * 31st.
 *
 * **Aging** — what is outstanding, by how late it is. Both directions, because
 * the payables side is the same report read the other way and an office chasing
 * money is also being chased for it.
 *
 * **A statement of account** — one firm's running account, which is the
 * document a collections call is made from and the one a customer's accounts
 * department asks for when their records and ours have stopped agreeing.
 *
 * ## Read-only, all of it
 *
 * There is nothing to change here and no endpoint that would let one. Every
 * figure is a sum over the journal's own postings, worked out when it is asked
 * for, so nothing on this page can drift from the books under it. The way to
 * move a number on a statement is to write a journal entry.
 *
 * ## Printing is the export
 *
 * `window.print()`, and a handful of print rules in the template. Every browser
 * prints to PDF and the dialog it opens is the one the user already knows; a
 * server-side renderer would be a second layout to keep in step with this one,
 * and the first time they diverged the copy in the customer's hand would be one
 * the office could not reproduce.
 */
@Component({
  selector: 'app-statements',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, Field, Icon, EmptyState, ErrorState],
  templateUrl: './statements.page.html',
})
export class StatementsPage {
  private readonly accounting = inject(AccountingService);
  private readonly billing = inject(BillingService);
  private readonly customers = inject(CustomerService);

  protected readonly fmt = fmt;

  protected readonly inputClass =
    'h-10 w-full rounded-control border border-cr-line bg-cr-surface px-3 text-[14px] text-cr-ink placeholder:text-cr-ink-muted focus:border-cr-blue focus:outline-none';

  protected readonly view = signal<View>('income');

  /** Null means still loading, in all four views. */
  protected readonly income = signal<IncomeStatement | null>(null);
  protected readonly sheet = signal<BalanceSheet | null>(null);
  protected readonly aging = signal<Aging | null>(null);
  protected readonly account = signal<StatementOfAccount | null>(null);
  protected readonly error = signal<string | null>(null);

  /**
   * The range, defaulted to this year so the first paint says something.
   *
   * An income statement with no range is every posting since the install, which
   * is a true figure and not a useful one. The year to date is what an office
   * means by "how are we doing".
   */
  protected readonly from = signal<string>(startOfYear());
  protected readonly to = signal<string>(today());

  /** The balance sheet's single date, which is its whole question. */
  protected readonly asOf = signal<string>(today());

  protected readonly direction = signal<InvoiceDirection>('receivable');

  protected readonly customerId = signal<string>('');
  protected readonly roster = signal<Customer[]>([]);

  /**
   * Which way round the aging and the statement of account read.
   *
   * One toggle serving two views rather than two, because it is the same
   * question in both: whose money is this. Switching it re-reads whichever is
   * on screen.
   */
  protected readonly directionLabel = computed(() =>
    this.direction() === 'receivable' ? 'Owed to us' : 'Owed by us',
  );

  /** The five aging buckets, in the order a collections desk reads them. */
  protected readonly agingBuckets = computed(() => {
    const report = this.aging();

    if (report === null) return [];

    return [
      { label: 'Not yet due', cents: report.buckets.current, late: false },
      { label: '1–30 days', cents: report.buckets['1_30'], late: true },
      { label: '31–60 days', cents: report.buckets['31_60'], late: true },
      { label: '61–90 days', cents: report.buckets['61_90'], late: true },
      { label: 'Over 90 days', cents: report.buckets.over_90, late: true },
    ];
  });

  /**
   * Everything below the gross-profit line.
   *
   * Total expenses less the cost of services — the office rather than the
   * road. Null when the chart has no cost-of-services group, because then
   * there is no line to be below.
   */
  protected readonly otherExpenses = computed(() => {
    const statement = this.income();

    if (statement === null || statement.cost_of_services_cents === null) return null;

    return statement.expenses.total_cents - statement.cost_of_services_cents;
  });

  /** Initials, for a statement of account whose issuer has no logo. */
  protected readonly initials = computed(() => {
    const name = this.account()?.issuer.name ?? '';

    return (
      name
        .split(/\s+/)
        .filter((part) => part !== '')
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('') || '—'
    );
  });

  constructor() {
    this.loadIncome();
    // The roster up front, so the statement-of-account picker is populated
    // before somebody switches to it rather than after.
    this.customers.list({ per_page: 200 }).subscribe({
      next: (page) => this.roster.set(page.data),
      error: () => this.roster.set([]),
    });
  }

  protected setView(view: View): void {
    this.view.set(view);
    this.error.set(null);

    if (view === 'sheet' && this.sheet() === null) this.loadSheet();
    if (view === 'aging' && this.aging() === null) this.loadAging();
    if (view === 'account' && this.customerId() !== '') this.loadAccount();
  }

  protected setFrom(value: string): void {
    this.from.set(value);
    this.reload();
  }

  protected setTo(value: string): void {
    this.to.set(value);
    this.reload();
  }

  protected setAsOf(value: string): void {
    this.asOf.set(value);
    this.loadSheet();
  }

  protected setDirection(direction: InvoiceDirection): void {
    this.direction.set(direction);

    if (this.view() === 'aging') this.loadAging();
    else if (this.customerId() !== '') this.loadAccount();
  }

  protected chooseCustomer(id: string): void {
    this.customerId.set(id);

    if (id === '') {
      this.account.set(null);

      return;
    }

    this.loadAccount();
  }

  /** Re-reads whichever view is on screen, for a change of dates. */
  protected reload(): void {
    if (this.view() === 'sheet') this.loadSheet();
    else if (this.view() === 'aging') this.loadAging();
    else if (this.view() === 'account' && this.customerId() !== '') this.loadAccount();
    else this.loadIncome();
  }

  protected print(): void {
    window.print();
  }

  private range(): { from?: string; to?: string } {
    const range: { from?: string; to?: string } = {};

    if (this.from() !== '') range.from = this.from();
    if (this.to() !== '') range.to = this.to();

    return range;
  }

  private loadIncome(): void {
    this.accounting.incomeStatement(this.range()).subscribe({
      next: (statement) => {
        this.income.set(statement);
        this.error.set(null);
      },
      error: () => {
        this.income.set(null);
        this.error.set('Could not load the income statement. Check the connection and try again.');
      },
    });
  }

  private loadSheet(): void {
    this.accounting.balanceSheet(this.asOf() || undefined).subscribe({
      next: (sheet) => {
        this.sheet.set(sheet);
        this.error.set(null);
      },
      error: () => {
        this.sheet.set(null);
        this.error.set('Could not load the balance sheet.');
      },
    });
  }

  private loadAging(): void {
    this.billing.aging(this.direction()).subscribe({
      next: (report) => {
        this.aging.set(report);
        this.error.set(null);
      },
      error: () => {
        this.aging.set(null);
        this.error.set('Could not load the aging report.');
      },
    });
  }

  private loadAccount(): void {
    this.billing
      .statement(this.customerId(), { ...this.range(), direction: this.direction() })
      .subscribe({
        next: (statement) => {
          this.account.set(statement);
          this.error.set(null);
        },
        error: () => {
          this.account.set(null);
          this.error.set('Could not load that statement of account.');
        },
      });
  }
}

/** Today, as the date inputs want it. */
function today(): string {
  return new Date().toISOString().slice(0, 10);
}

function startOfYear(): string {
  return `${new Date().getFullYear()}-01-01`;
}
