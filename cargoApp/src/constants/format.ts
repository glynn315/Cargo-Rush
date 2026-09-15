/**
 * Display formatting. The API sends ISO-8601 UTC and integer base units
 * (DESIGN.md section 7.1); the client decides how to read them.
 * Mirrors CargoUI/src/app/shared/format.ts.
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

  /**
   * Centavos on the wire, pesos on screen (DESIGN.md section 7.1).
   *
   * The API never formats money and never sends a float, so this is the only
   * place a peso sign appears. Written to match
   * `CargoUI/src/app/shared/format.ts` character for character: the same
   * amount has to read the same on the phone and on the desk, or the two
   * screens look like they disagree about the figure.
   */
  money(cents: number, currency = 'PHP'): string {
    const symbol = currency === 'PHP' ? '₱' : '';

    return `${symbol}${(cents / 100).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
  },

  kg(value: number): string {
    return `${value.toLocaleString()} kg`;
  },

  km(value: number): string {
    return `${value.toLocaleString()} km`;
  },

  /** Metres on the wire, kilometres on screen. */
  metresAsKm(value: number): string {
    return `${(value / 1000).toFixed(1)} km`;
  },

  /**
   * Seconds on the wire, hours and minutes on screen.
   *
   * `4h 20m`, not `4.33 hours` and not `260 minutes`: a driving time is read
   * off a phone and turned into "I'll be there by half nine", which hours and
   * minutes do in one step. Under an hour drops the hours entirely rather than
   * printing `0h 40m`.
   */
  duration(seconds: number): string {
    const total = Math.max(0, Math.round(seconds / 60));
    const hours = Math.floor(total / 60);
    const minutes = total % 60;

    if (hours === 0) return `${minutes}m`;

    return minutes === 0 ? `${hours}h` : `${hours}h ${minutes}m`;
  },
};
