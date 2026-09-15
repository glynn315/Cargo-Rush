import { useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { PortalInvoice } from '@/models/portal/portal.model';
import { portalService } from '@/services/portal/portal.service';
import { Screen } from '@/components/screen';
import { Icon } from '@/components/ui/icon';
import { Card, EmptyState, ErrorState, SkeletonRows, StatusPill } from '@/components/ui/primitives';
import { Brand, Radius, Spacing } from '@/constants/theme';
import { fmt } from '@/constants/format';
import { useApi } from '@/hooks/use-api';

/**
 * The customer's invoices, and how each one adds up.
 *
 * Receivables only — a payable is money the business owes somebody else and has
 * no place here, which the API enforces rather than trusting a filter on this
 * screen.
 *
 * ## Why every invoice opens
 *
 * A total on its own is a number a customer has to take on trust. The office
 * can cross-check one against a ledger; a firm reading it on a phone has
 * nothing, which is how a wrong figure goes unquestioned for a month — and how
 * a right one gets queried. So each card opens into the **liquidation**: the
 * haul it is for, the net, the VAT, anything withheld, every payment received
 * with its date and reference, and what is left. Six lines that add up in front
 * of somebody.
 *
 * It is collapsed by default. The list answers "what do I owe"; the breakdown
 * answers "why", which is a second question and not everybody's.
 *
 * ## Ordered by when they asked
 *
 * Newest request first, not newest document. A customer looks for "the Silway
 * run" by the day they sent it; the day an office raised the paperwork is not
 * something they know or should have to.
 */
export function InvoicesPage() {
  const invoices = useApi(portalService.invoices);
  const summary = useApi(portalService.summary);

  /** Which invoice is open. One at a time — this is a list, not a report. */
  const [open, setOpen] = useState<string | null>(null);

  const rows = invoices.data ?? [];

  return (
    <Screen title="Invoices" subtitle={summary.data?.customer.name}>
      <View style={styles.totals}>
        <Card padded={false} style={styles.totalCard}>
          <Text style={styles.totalLabel}>PENDING PAYMENT</Text>
          <Text style={styles.totalValue}>
            {summary.data
              ? fmt.money(summary.data.pending_payment_cents, summary.data.currency)
              : '—'}
          </Text>
        </Card>
        <Card padded={false} style={styles.totalCard}>
          <Text style={styles.totalLabel}>PAID</Text>
          <Text style={[styles.totalValue, { color: Brand.success }]}>
            {summary.data
              ? fmt.money(summary.data.successful_payment_cents, summary.data.currency)
              : '—'}
          </Text>
        </Card>
      </View>

      <Card heading="Invoices" icon="billing" hint="Newest request first" padded={false}>
        {invoices.loading ? (
          <View style={{ padding: Spacing.three }}>
            <SkeletonRows count={4} />
          </View>
        ) : invoices.error ? (
          <ErrorState message={invoices.error.message} onRetry={invoices.reload} />
        ) : rows.length === 0 ? (
          <EmptyState
            icon="billing"
            title="No invoices yet"
            body="An invoice is raised when a delivery is completed."
          />
        ) : (
          rows.map((invoice: PortalInvoice, index: number) => (
            <View key={invoice.id} style={index < rows.length - 1 ? styles.divider : undefined}>
              <Pressable
                accessibilityRole="button"
                accessibilityState={{ expanded: open === invoice.id }}
                accessibilityLabel={`${invoice.number}, ${fmt.money(
                  invoice.amount_cents,
                  invoice.currency,
                )}. Tap for the breakdown.`}
                onPress={() => setOpen(open === invoice.id ? null : invoice.id)}
                style={({ pressed }) => [styles.row, pressed && { backgroundColor: Brand.tint }]}>
                <View style={{ flex: 1, minWidth: 0, gap: 3 }}>
                  <Text style={styles.number}>{invoice.number}</Text>
                  {/* Who is owed. A shipper using two hauliers has two sets of
                      receivables, and "overdue" is not actionable until you
                      know which office to pay. */}
                  {invoice.carrier ? (
                    <Text style={styles.carrier} numberOfLines={1}>
                      {invoice.carrier}
                    </Text>
                  ) : null}
                  <Text style={styles.sub} numberOfLines={1}>
                    {/* The run, and the day they asked for it — which is how a
                        customer knows which delivery this is. */}
                    {invoice.trip_reference ?? 'No trip'} · requested{' '}
                    {fmt.date(invoice.requested_at)}
                  </Text>
                  <Text style={styles.sub}>
                    {invoice.balance_cents === 0
                      ? `Settled${invoice.paid_at ? ' ' + fmt.date(invoice.paid_at) : ''}`
                      : `Due ${fmt.date(invoice.due_at)}`}
                  </Text>
                </View>

                <View style={styles.right}>
                  {/* The figure that matters to the payer: what is still owed,
                      with the document's own total under it when they differ.
                      An invoice part-paid shows both rather than one. */}
                  <Text style={styles.amount}>
                    {fmt.money(
                      invoice.balance_cents === 0 ? invoice.amount_cents : invoice.balance_cents,
                      invoice.currency,
                    )}
                  </Text>
                  {invoice.balance_cents > 0 && invoice.paid_cents > 0 ? (
                    <Text style={styles.of}>
                      of {fmt.money(invoice.due_cents, invoice.currency)}
                    </Text>
                  ) : null}
                  <StatusPill status={invoice.status} />
                  <Icon
                    name={open === invoice.id ? 'chevron-left' : 'chevron-right'}
                    size={14}
                    color={Brand.inkMuted}
                  />
                </View>
              </Pressable>

              {/* The liquidation. Same order a document is read in: what the
                  haul was, what it cost, what the tax did to it, what has been
                  received, and what is left. */}
              {open === invoice.id ? (
                <View style={styles.breakdown}>
                  {invoice.trip_reference ? (
                    <View style={styles.tripBox}>
                      <Icon name="route" size={14} color={Brand.blue} />
                      <View style={{ flex: 1, minWidth: 0 }}>
                        <Text style={styles.tripRoute} numberOfLines={1}>
                          {invoice.trip_origin} → {invoice.trip_destination}
                        </Text>
                        <Text style={styles.sub} numberOfLines={2}>
                          {invoice.trip_cargo}
                          {invoice.trip_weight_kg ? ` · ${fmt.kg(invoice.trip_weight_kg)}` : ''}
                        </Text>
                      </View>
                    </View>
                  ) : null}

                  <Line label="Hauling charge" value={fmt.money(invoice.net_amount_cents, invoice.currency)} />

                  {/* Named with its rate, because "VAT" alone invites the
                      question this screen exists to answer. */}
                  <Line
                    label={`VAT (${invoice.vat_rate_bp / 100}%)`}
                    value={fmt.money(invoice.vat_cents, invoice.currency)}
                  />

                  <Line
                    label="Invoice total"
                    value={fmt.money(invoice.amount_cents, invoice.currency)}
                    strong
                  />

                  {/* Only where it applies. A firm that does not withhold
                      should not be shown a zero and left wondering. */}
                  {invoice.withholding_cents > 0 ? (
                    <>
                      <Line
                        label={`Less withholding tax (${invoice.withholding_rate_bp / 100}%)`}
                        value={`− ${fmt.money(invoice.withholding_cents, invoice.currency)}`}
                      />
                      <Text style={styles.note}>
                        You remit this to the BIR on the carrier&apos;s behalf, so the amount
                        payable is less than the invoice total. Form 2307 goes with the payment.
                      </Text>
                    </>
                  ) : null}

                  <Line
                    label="Amount payable"
                    value={fmt.money(invoice.due_cents, invoice.currency)}
                    strong
                  />

                  {invoice.payments.length > 0 ? (
                    <View style={styles.payments}>
                      <Text style={styles.paymentsLabel}>PAYMENTS RECEIVED</Text>
                      {invoice.payments.map((payment, i) => (
                        <View key={`${payment.paid_on}-${i}`} style={styles.paymentRow}>
                          <View style={{ flex: 1, minWidth: 0 }}>
                            <Text style={styles.paymentWhen}>{fmt.date(payment.paid_on)}</Text>
                            <Text style={styles.sub} numberOfLines={1}>
                              {payment.method_label ?? 'Payment'}
                              {payment.reference ? ` · ${payment.reference}` : ''}
                            </Text>
                          </View>
                          <Text style={styles.paymentAmount}>
                            − {fmt.money(payment.amount_cents, invoice.currency)}
                          </Text>
                        </View>
                      ))}
                    </View>
                  ) : (
                    <Text style={styles.note}>
                      Nothing received against this invoice yet. A payment appears here with its
                      date and reference the moment the carrier records it.
                    </Text>
                  )}

                  <View style={styles.balanceRow}>
                    <Text style={styles.balanceLabel}>
                      {invoice.balance_cents === 0 ? 'Settled' : 'Balance'}
                    </Text>
                    <Text
                      style={[
                        styles.balanceValue,
                        { color: invoice.balance_cents === 0 ? Brand.success : Brand.ink },
                      ]}>
                      {fmt.money(invoice.balance_cents, invoice.currency)}
                    </Text>
                  </View>
                </View>
              ) : null}
            </View>
          ))
        )}
      </Card>
    </Screen>
  );
}

/** One line of the liquidation: what it is, and what it comes to. */
function Line({
  label,
  value,
  strong = false,
}: {
  label: string;
  value: string;
  strong?: boolean;
}) {
  return (
    <View style={[styles.line, strong && styles.lineStrong]}>
      <Text style={[styles.lineLabel, strong && styles.lineLabelStrong]} numberOfLines={2}>
        {label}
      </Text>
      <Text style={[styles.lineValue, strong && styles.lineValueStrong]}>{value}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  totals: { flexDirection: 'row', gap: Spacing.three },
  totalCard: { flex: 1, minWidth: 0, padding: Spacing.three, gap: 6 },
  totalLabel: { fontSize: 10, fontWeight: '600', letterSpacing: 0.6, color: Brand.inkMuted },
  totalValue: {
    fontSize: 20,
    fontWeight: '700',
    color: Brand.ink,
    fontVariant: ['tabular-nums'],
  },

  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three,
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.three,
    borderRadius: Radius.control,
  },
  divider: { borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: Brand.line },
  number: { fontSize: 14, fontWeight: '600', color: Brand.ink, fontVariant: ['tabular-nums'] },
  carrier: { fontSize: 12, fontWeight: '600', color: Brand.ink },
  sub: { fontSize: 12, color: Brand.inkMuted },
  right: { alignItems: 'flex-end', gap: 6 },
  amount: { fontSize: 15, fontWeight: '700', color: Brand.ink, fontVariant: ['tabular-nums'] },
  of: { fontSize: 11, color: Brand.inkMuted, fontVariant: ['tabular-nums'] },

  breakdown: {
    paddingHorizontal: Spacing.three,
    paddingBottom: Spacing.three,
    gap: 2,
  },

  tripBox: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: Spacing.two,
    padding: Spacing.two + 2,
    marginBottom: Spacing.two,
    borderRadius: Radius.control,
    backgroundColor: Brand.tint,
  },
  tripRoute: { fontSize: 13, fontWeight: '600', color: Brand.ink },

  line: { flexDirection: 'row', alignItems: 'flex-end', gap: Spacing.two, paddingVertical: 3 },
  lineStrong: {
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: Brand.line,
    paddingTop: 6,
    marginTop: 3,
  },
  lineLabel: { flex: 1, fontSize: 12, color: Brand.inkMuted },
  lineLabelStrong: { color: Brand.ink, fontWeight: '600' },
  lineValue: { fontSize: 13, color: Brand.ink, fontVariant: ['tabular-nums'] },
  lineValueStrong: { fontSize: 14, fontWeight: '700' },

  note: { marginTop: 4, fontSize: 11, lineHeight: 16, color: Brand.inkMuted },

  payments: { marginTop: Spacing.three, gap: 4 },
  paymentsLabel: {
    fontSize: 10,
    fontWeight: '700',
    letterSpacing: 0.6,
    color: Brand.inkMuted,
  },
  paymentRow: { flexDirection: 'row', alignItems: 'center', gap: Spacing.two },
  paymentWhen: { fontSize: 12, fontWeight: '600', color: Brand.ink },
  paymentAmount: {
    fontSize: 13,
    fontWeight: '600',
    color: Brand.success,
    fontVariant: ['tabular-nums'],
  },

  balanceRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginTop: Spacing.three,
    paddingTop: Spacing.two,
    borderTopWidth: 1,
    borderTopColor: Brand.line,
  },
  balanceLabel: { fontSize: 13, fontWeight: '700', color: Brand.ink },
  balanceValue: { fontSize: 17, fontWeight: '700', fontVariant: ['tabular-nums'] },
});
