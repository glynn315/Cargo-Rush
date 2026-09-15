/**
 * The two statements the books exist to produce.
 *
 * The trial balance proves the ledger is whole; these say what it *means*. An
 * income statement is what the business earned over a period, and a balance
 * sheet is what it is worth at a moment — and the difference between "over" and
 * "at" is the whole reason they are two reports rather than one.
 *
 * Every figure is integer centavos, in its own account's direction: positive
 * means *more of what this account is*. A payable of ₱50,000 is 50,000 here,
 * not minus fifty thousand — a liability is not negative money, it is fifty
 * thousand pesos of debt. The API decides that, because it is the side that
 * knows which side increases each account.
 */

/** One account on a statement line. */
export interface StatementAccount {
  id: string;
  code: string;
  name: string;
  balance_cents: number;
}

/**
 * A sub-heading and the accounts filed under it.
 *
 * The label is the office's own (`accounts.group`), so a statement reads down
 * the page the way their chart of accounts does. Accounts with nothing on them
 * are absent rather than zero — a statement padded with zeroes hides the lines
 * that matter — and a group that empties out disappears with them.
 */
export interface StatementGroup {
  group: string;
  accounts: StatementAccount[];
  total_cents: number;
}

export interface StatementSection {
  groups: StatementGroup[];
  total_cents: number;
}

export interface IncomeStatement {
  range: { from: string | null; to: string | null };

  revenue: StatementSection;
  expenses: StatementSection;

  /**
   * The cost of actually hauling, as against running an office.
   *
   * Null when the chart has no cost-of-services group to measure against — an
   * install that has renamed it. That is the right failure: a margin computed
   * from the wrong half of the expenses is worse than no margin at all, so the
   * page prints a dash rather than a number nobody can trust.
   */
  cost_of_services_cents: number | null;
  gross_profit_cents: number | null;
  gross_margin_pct: number | null;

  net_income_cents: number;
  /** Null when there is no revenue to be a percentage of. */
  net_margin_pct: number | null;

  currency: string;
}

export interface BalanceSheet {
  as_of: string;

  assets: StatementSection;
  liabilities: StatementSection;

  equity: {
    groups: StatementGroup[];
    /** What has actually been posted to the equity accounts. */
    posted_total_cents: number;
    /**
     * This period's result, sitting in equity because that is where it belongs
     * and nothing has closed it there yet.
     *
     * There is no period close in this system, so the sheet names the figure
     * rather than folding it into an account nobody posted to. Without it the
     * sheet would not balance and a reader would be left hunting for the
     * difference; with it, the arithmetic is visible and the missing step is
     * named.
     */
    earnings_not_closed_cents: number;
    total_cents: number;
  };

  liabilities_and_equity_cents: number;
  /** True is the only acceptable answer. `difference_cents` says how far off. */
  balanced: boolean;
  difference_cents: number;

  currency: string;
}
