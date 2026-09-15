import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { map } from 'rxjs';

import { Account, AccountType } from '../../models/accounting/accounting.model';
import { accountSpec } from '../../services/accounting/account.form';
import { AccountingService } from '../../services/accounting/accounting.service';
import { Card } from '../../shared/card';
import { Column, DataTable } from '../../shared/data-table';
import { fmt } from '../../shared/format';
import { Icon } from '../../shared/icon';
import { ListToolbar } from '../../shared/list-toolbar';
import { recordList } from '../../shared/record-list';
import { ErrorState } from '../../shared/states';

/**
 * Chart of Accounts — what every posting in the books points at.
 *
 * The page a fleet rarely opens and cannot do without. It arrives seeded with a
 * chart a haulier can post to on day one, numbered the way an accountant, an
 * auditor and a BIR examiner all expect: 1000s assets, 2000s liabilities,
 * 3000s equity, 4000s income, 5000s expenses.
 *
 * Two things about it are not obvious from the table.
 *
 * The **kind** of an account is not a label. It decides which side increases
 * the account, which statement it appears on and which column of a trial
 * balance it lands in — so it is shown as its own grouped section rather than
 * as one more column, and the API refuses to change it once anything has been
 * posted. Flipping 5010 from expense to income would reverse the sign of every
 * figure ever recorded against it, and no report would show that it had
 * happened.
 *
 * And an account is **retired rather than deleted** once it has history: the
 * postings are the record and the account is what they name. That is why the
 * form has a status field and the rows have no delete button — see
 * `accountSpec`.
 */
@Component({
  selector: 'app-accounts',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Card, DataTable, Icon, ListToolbar, ErrorState],
  templateUrl: './accounts.page.html',
})
export class AccountsPage {
  private readonly accounting = inject(AccountingService);

  protected readonly fmt = fmt;

  private readonly spec = accountSpec();

  protected readonly list = recordList<Account>(this.spec, () =>
    this.accounting.accounts().pipe(map((res) => res.data)),
  );

  protected readonly rows = this.list.rows;

  /** Which section is expanded. All of them, until somebody collapses one. */
  private readonly collapsed = signal<Record<string, boolean>>({});

  /**
   * The chart, grouped by kind and in the order a chart is read.
   *
   * The API already returns it in that order, so this only cuts the list at the
   * boundaries rather than sorting it again — one place decides what "chart
   * order" means, and it is the one that also knows an account's position.
   */
  protected readonly sections = computed(() => {
    const rows = this.rows();

    if (rows === null) return null;

    const order: AccountType[] = ['asset', 'liability', 'equity', 'income', 'expense'];
    const seen = new Map<AccountType, { type: AccountType; label: string; rows: Account[] }>();

    for (const account of rows) {
      const section = seen.get(account.type) ?? {
        type: account.type,
        label: account.type_label,
        rows: [] as Account[],
      };

      section.rows.push(account);
      seen.set(account.type, section);
    }

    return order.filter((type) => seen.has(type)).map((type) => seen.get(type)!);
  });

  protected readonly activeCount = computed(
    () => (this.rows() ?? []).filter((account) => account.status === 'active').length,
  );

  protected readonly retiredCount = computed(
    () => (this.rows() ?? []).filter((account) => account.status !== 'active').length,
  );

  protected readonly columns: Column<Account>[] = [
    { label: 'Number', kind: 'strong', value: (a) => a.code },
    { label: 'Name', value: (a) => a.name, sub: (a) => a.description },
    { label: 'Group', kind: 'muted', value: (a) => a.group },
    /**
     * Which side increases it, in words.
     *
     * From the API's `normal_balance` rather than worked out here: the rule
     * belongs to the server, and a client with its own copy is the second place
     * that has to be right about it.
     */
    {
      label: 'Increases on',
      kind: 'muted',
      value: (a) => (a.normal_balance === 'debit' ? 'Debit' : 'Credit'),
    },
    { label: 'Status', kind: 'status', status: (a) => a.status },
  ];

  protected isCollapsed(type: string): boolean {
    return this.collapsed()[type] === true;
  }

  protected toggle(type: string): void {
    this.collapsed.update((state) => ({ ...state, [type]: !state[type] }));
  }
}
