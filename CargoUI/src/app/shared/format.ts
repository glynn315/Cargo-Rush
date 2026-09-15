/**
 * Display formatting lives here, not in the API. The API sends ISO-8601 UTC and
 * integer minor units (DESIGN.md section 7.1); the client decides how to read them.
 */
export const fmt = {
  dateTime(value: string | null | undefined): string {
    if (!value) return '—';
    return new Date(value).toLocaleString([], {
      day: '2-digit',
      month: 'short',
      hour: '2-digit',
      minute: '2-digit',
    });
  },

  time(value: string | null | undefined): string {
    if (!value) return '—';
    return new Date(value).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  },

  date(value: string | null | undefined): string {
    if (!value) return '—';
    return new Date(value).toLocaleDateString([], {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
    });
  },

  /** Integer minor units -> a readable amount. Never floats on the wire. */
  money(cents: number, currency = 'PHP'): string {
    const symbol = currency === 'PHP' ? '₱' : '';
    return `${symbol}${(cents / 100).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
  },

  /**
   * The same, to the centavo — for documents rather than dashboards.
   *
   * `money()` rounds to whole pesos, which is right on a tile where the figure
   * is a size and the decimals are noise. It is wrong on a statement, a payslip
   * or a trial balance: those are documents somebody adds up, and a column of
   * rounded rows will not sum to a rounded total. Anything that has to
   * reconcile uses this one.
   */
  pesos(cents: number, currency = 'PHP'): string {
    const symbol = currency === 'PHP' ? '₱' : '';

    return `${symbol}${(cents / 100).toLocaleString(undefined, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })}`;
  },

  kg(value: number): string {
    return `${value.toLocaleString()} kg`;
  },

  km(value: number): string {
    return `${value.toLocaleString()} km`;
  },

  litres(value: number): string {
    return `${value.toLocaleString()} L`;
  },
};
