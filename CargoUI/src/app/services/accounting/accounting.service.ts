import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';

import { ApiService } from '../shared/api.service';
import {
  Account,
  AccountPayload,
  AccountTypeOption,
  JournalCategoryOption,
  JournalEntry,
  JournalEntryPayload,
  LedgerPage,
  LedgerSummary,
  TrialBalance,
} from '../../models/accounting/accounting.model';
import { BalanceSheet, IncomeStatement } from '../../models/accounting/statement.model';
import { Envelope, ListQuery } from '../../models/shared/envelope.model';

/** The journal's four questions: when, what kind, which state, which account. */
export interface JournalQuery extends ListQuery {
  category?: string;
  account_id?: string;
  from?: string;
  to?: string;
}

/**
 * The books — the chart of accounts, the general journal, and the general
 * ledger read off it.
 *
 * The shape of this service is the shape of the module, and one thing about it
 * is worth stating: **the ledger is read-only.** There is no `postToLedger`
 * here and there is no endpoint for one. A ledger is what the journal looks
 * like sorted by account, so the way to change a figure on it is to write a
 * journal entry — and a second way in would be a second set of rules to keep in
 * step.
 *
 * `post` and `void` are verbs rather than a status PATCH, for the same reason
 * confirming a trip is: posting stamps who did it and when, checks the entry
 * balances first, and tells the office; voiding takes a reason with it. A
 * status field that did any of that when set to one value would hide all of it.
 */
@Injectable({ providedIn: 'root' })
export class AccountingService {
  private readonly api = inject(ApiService);

  // ---- The chart of accounts.

  /**
   * The whole chart, in chart order — not paginated.
   *
   * A chart is a couple of dozen rows read as a whole, grouped by type. Paging
   * it would break the one thing it is for.
   */
  accounts(activeOnly = false): Observable<Envelope<Account[]>> {
    return this.api.envelope<Account[]>(
      'accounting/accounts',
      activeOnly ? { active: 1 } : undefined,
    );
  }

  /** The five types, with which side increases each. From the API's enum. */
  accountTypes(): Observable<AccountTypeOption[]> {
    return this.api.get<AccountTypeOption[]>('accounting/accounts/types');
  }

  createAccount(payload: AccountPayload): Observable<Account> {
    return this.api.post<Account>('accounting/accounts', payload);
  }

  updateAccount(id: string, payload: Partial<AccountPayload>): Observable<Account> {
    return this.api.patch<Account>(`accounting/accounts/${id}`, payload);
  }

  /**
   * Delete an account, or retire it.
   *
   * Two outcomes and the page has to tell them apart: an account nothing is
   * posted to is deleted, and one with history is switched off instead —
   * deleting it would take the postings that name it with it. The API answers
   * 204 for the first and the retired account for the second, so the page
   * re-reads the list and says which happened.
   */
  removeAccount(id: string): Observable<void> {
    return this.api.delete(`accounting/accounts/${id}`);
  }

  // ---- The general journal.

  journal(query?: JournalQuery): Observable<Envelope<JournalEntry[]>> {
    return this.api.envelope<JournalEntry[]>('accounting/journal', query);
  }

  /** The accounting categories a form offers, from the API's own list. */
  categories(): Observable<JournalCategoryOption[]> {
    return this.api.get<JournalCategoryOption[]>('accounting/journal/categories');
  }

  entry(id: string): Observable<JournalEntry> {
    return this.api.get<JournalEntry>(`accounting/journal/${id}`);
  }

  /** Write one. A draft unless the payload says `posted`. */
  createEntry(payload: JournalEntryPayload): Observable<JournalEntry> {
    return this.api.post<JournalEntry>('accounting/journal', payload);
  }

  /**
   * Edit a draft.
   *
   * Sending `lines` replaces them wholesale — there is no merging, because a
   * payload missing one line would be ambiguous between "deleted" and
   * "unchanged", and those differ by a whole side of a transaction.
   */
  updateEntry(id: string, payload: Partial<JournalEntryPayload>): Observable<JournalEntry> {
    return this.api.patch<JournalEntry>(`accounting/journal/${id}`, payload);
  }

  /** Put a draft in the books. Refused if the two sides do not agree. */
  postEntry(id: string): Observable<JournalEntry> {
    return this.api.post<JournalEntry>(`accounting/journal/${id}/post`, {});
  }

  /** Withdraw a posted entry. The row stays, with the reason on it. */
  voidEntry(id: string, reason: string): Observable<JournalEntry> {
    return this.api.post<JournalEntry>(`accounting/journal/${id}/void`, { reason });
  }

  /** Delete a draft. A posted entry is voided instead. */
  removeEntry(id: string): Observable<void> {
    return this.api.delete(`accounting/journal/${id}`);
  }

  // ---- The general ledger. Read-only, all of it.

  /** The chart with a balance against every account, subtotalled by type. */
  ledger(range?: { from?: string; to?: string }): Observable<LedgerSummary> {
    return this.api.get<LedgerSummary>('accounting/ledger', range);
  }

  /** One account's page: an opening balance, the postings, a running balance. */
  ledgerAccount(id: string, range?: { from?: string; to?: string }): Observable<LedgerPage> {
    return this.api.get<LedgerPage>(`accounting/ledger/accounts/${id}`, range);
  }

  /** Debits against credits, as of a date. */
  trialBalance(asOf?: string): Observable<TrialBalance> {
    return this.api.get<TrialBalance>(
      'accounting/ledger/trial-balance',
      asOf ? { as_of: asOf } : undefined,
    );
  }

  // ---- The statements. Read-only too, and for the same reason.

  /**
   * What the business earned over a period.
   *
   * A range, and it means it: income and expenses are the story of a period and
   * start again, so `from`/`to` are the statement rather than a filter on it.
   */
  incomeStatement(range?: { from?: string; to?: string }): Observable<IncomeStatement> {
    return this.api.get<IncomeStatement>('accounting/statements/income', range);
  }

  /**
   * What the business is worth at a moment.
   *
   * A single date rather than a range, because assets and liabilities carry
   * over — asking for "the balance sheet for March" is asking for the sheet as
   * it stood on the 31st.
   */
  balanceSheet(asOf?: string): Observable<BalanceSheet> {
    return this.api.get<BalanceSheet>(
      'accounting/statements/balance-sheet',
      asOf ? { as_of: asOf } : undefined,
    );
  }
}
