import { useEffect, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';

import { Incident, IncidentReport } from '@/models/incident/incident.model';
import { incidentService } from '@/services/incident/incident.service';
import { Icon } from '@/components/ui/icon';
import { Sheet } from '@/components/ui/sheet';
import { Brand, Radius, Spacing } from '@/constants/theme';
import { fmt } from '@/constants/format';

/**
 * Report an incident — the driver's own write-up, from where it happened.
 *
 * DESIGN.md section 5.1 puts incident notification at the centre of the
 * Notification module, and the person who can raise one first is the one who is
 * there. Until now they could not: raising an incident was the office's
 * `incidents.manage`, so a driver with a blown tyre rang the desk and somebody
 * else typed it. This is the same act done from the cab, and the desk is told
 * the moment it lands.
 *
 * **Three questions, and no more.** What happened, where, and anything worth
 * adding. Who was driving, which unit, and which run are not asked, because the
 * API already knows all three and stamps them from the token — a driver
 * standing in the rain should not be confirming a plate number, and a form with
 * nowhere to put somebody else's trip is a form that cannot file against
 * somebody else's trip.
 *
 * The kinds are chips rather than a dropdown, and the field stays open for
 * typing. Almost every report is one of six things, and tapping one is the
 * difference between a report filed now and one filed at the depot tonight —
 * but "kind" is free text on the record for a reason, and squeezing what
 * actually happened into the nearest option would make the log worse.
 *
 * The time is not asked either. Reporting from the scene means now, which is
 * what the API assumes when nothing is sent.
 */

/**
 * The six that cover almost everything, in the words the office already uses —
 * these are the kinds the demo log and the web form are written in, so a report
 * from the road reads like the ones typed at the desk.
 */
const KINDS = [
  'Tyre blowout',
  'Traffic hold',
  'Breakdown',
  'Cargo damage',
  'Road accident',
  'Overheating',
];

export function ReportIncidentSheet({
  open,
  onClose,
  onReported,
  /** The run they are on, for the header. Null between runs — still reportable. */
  reference,
  /** Where the unit last reported being, as the first suggestion for "where". */
  place,
}: {
  open: boolean;
  onClose: () => void;
  /** Called after a successful report, so the caller can refresh its list. */
  onReported?: () => void;
  reference?: string | null;
  place?: string | null;
}) {
  const [kind, setKind] = useState('');
  const [where, setWhere] = useState('');
  const [notes, setNotes] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [filed, setFiled] = useState<Incident | null>(null);

  /**
   * Start "where" at wherever the truck last said it was.
   *
   * The driver knows the answer better than the tracker does, so it is a
   * starting point and not a value — but on a road with no address, the last
   * reported position is a great deal closer than an empty box. Re-applied each
   * time the sheet opens, since the truck has moved since it last closed.
   */
  useEffect(() => {
    if (!open) return;

    setWhere(place ?? '');
    setError(null);
    setFiled(null);
  }, [open, place]);

  const submit = () => {
    if (saving) return;

    // Checked here as well as by the API, so a driver is told which answer is
    // missing before the request goes out rather than after it.
    if (!kind.trim()) {
      setError('Say what happened — tap one above or type it.');

      return;
    }

    if (!where.trim()) {
      setError('Say where it happened.');

      return;
    }

    setSaving(true);
    setError(null);

    // No `occurred_at`: an incident reported from the scene happened now, and
    // the API stamps it. No driver, unit or trip either — those are the scope,
    // and the API takes them from the token.
    const report: IncidentReport = {
      kind: kind.trim(),
      place: where.trim(),
      ...(notes.trim() ? { notes: notes.trim() } : {}),
    };

    incidentService
      .report(report)
      .then((incident) => {
        setFiled(incident);
        setKind('');
        setNotes('');
        onReported?.();
      })
      .catch((e: Error) => setError(e.message))
      .finally(() => setSaving(false));
  };

  // The confirmation stays on the sheet rather than closing it. The reference
  // is the one thing the driver needs to carry away — it is what the office
  // will ask for when they ring back — and a sheet that vanished would take it
  // with it.
  if (filed) {
    return (
      <Sheet
        open={open}
        onClose={onClose}
        title="Reported"
        subtitle={filed.reference}
        icon="incident"
        danger
        footer={
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Done"
            onPress={onClose}
            style={styles.save}>
            <Text style={styles.saveText}>Done</Text>
          </Pressable>
        }>
        <View style={styles.done}>
          <View style={styles.doneIcon}>
            <Icon name="check" size={24} color={Brand.success} />
          </View>
          <Text style={styles.doneTitle}>The office has been told</Text>
          <Text style={styles.doneBody}>
            {filed.kind} · {filed.place}
          </Text>
          <Text style={styles.doneNote}>
            Quote {filed.reference} if you speak to the desk. They decide what happens next —
            you will see it on your reports.
          </Text>
        </View>
      </Sheet>
    );
  }

  return (
    <Sheet
      open={open}
      onClose={onClose}
      title="Report an incident"
      subtitle={reference ? `${reference} · now` : 'Now'}
      icon="incident"
      danger
      footer={
        <>
          {error ? (
            <Text style={styles.error} accessibilityLiveRegion="polite" accessibilityRole="alert">
              {error}
            </Text>
          ) : null}
          <Pressable
            accessibilityRole="button"
            accessibilityLabel="Send report"
            accessibilityState={{ disabled: saving }}
            disabled={saving}
            onPress={submit}
            style={[styles.save, saving && { opacity: 0.6 }]}>
            <Text style={styles.saveText}>{saving ? 'Sending…' : 'Send report'}</Text>
          </Pressable>
          <Pressable accessibilityRole="button" onPress={onClose} style={styles.cancel}>
            <Text style={styles.cancelText}>Cancel</Text>
          </Pressable>
        </>
      }>
      <ScrollView style={styles.scroll} keyboardShouldPersistTaps="handled">
        <Text style={styles.label}>WHAT HAPPENED</Text>
        <View style={styles.chips}>
          {KINDS.map((k) => {
            const on = kind === k;

            return (
              <Pressable
                key={k}
                accessibilityRole="button"
                accessibilityState={{ selected: on }}
                // Tapping the chosen one again clears it, so a mis-tap is one
                // tap to undo rather than a value that cannot be unset.
                onPress={() => setKind(on ? '' : k)}
                style={[styles.chip, on && { backgroundColor: Brand.red, borderColor: Brand.red }]}>
                <Text style={[styles.chipText, on && { color: Brand.surface }]}>{k}</Text>
              </Pressable>
            );
          })}
        </View>

        <TextInput
          value={kind}
          onChangeText={setKind}
          placeholder="Or describe it yourself"
          placeholderTextColor={Brand.inkMuted}
          accessibilityLabel="What happened"
          style={[styles.input, { marginTop: Spacing.two }]}
        />

        <Text style={[styles.label, { marginTop: Spacing.four }]}>WHERE</Text>
        <TextInput
          value={where}
          onChangeText={setWhere}
          placeholder="e.g. SLEX km 58"
          placeholderTextColor={Brand.inkMuted}
          accessibilityLabel="Where it happened"
          style={styles.input}
        />
        {place ? (
          <Text style={styles.hint}>
            Started from where your unit last reported. Change it if you have moved.
          </Text>
        ) : null}

        <Text style={[styles.label, { marginTop: Spacing.four }]}>ANYTHING ELSE</Text>
        <TextInput
          value={notes}
          onChangeText={setNotes}
          placeholder="Optional — what the office needs to know"
          placeholderTextColor={Brand.inkMuted}
          accessibilityLabel="Notes"
          multiline
          numberOfLines={3}
          style={[styles.input, styles.notes]}
        />

        <View style={styles.foot}>
          <Icon name="incident" size={14} color={Brand.inkMuted} />
          <Text style={styles.footText}>
            Sent as of {fmt.time(new Date().toISOString())}, against{' '}
            {reference ? `${reference} and your unit` : 'you and your unit'}. The office is
            notified straight away.
          </Text>
        </View>
      </ScrollView>
    </Sheet>
  );
}

const styles = StyleSheet.create({
  scroll: { maxHeight: 420, paddingHorizontal: Spacing.four },

  label: { fontSize: 10, fontWeight: '700', letterSpacing: 0.6, color: Brand.inkMuted },

  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: Spacing.two, marginTop: Spacing.two },
  chip: {
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.two,
    borderRadius: Radius.full,
    borderWidth: 1,
    borderColor: Brand.line,
    backgroundColor: Brand.surface,
  },
  chipText: { fontSize: 13, fontWeight: '600', color: Brand.ink },

  input: {
    marginTop: 6,
    minHeight: 44,
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.line,
    paddingHorizontal: Spacing.three,
    fontSize: 15,
    color: Brand.ink,
    backgroundColor: Brand.surface,
  },
  notes: { minHeight: 76, paddingTop: Spacing.two, textAlignVertical: 'top' },
  hint: { marginTop: 4, fontSize: 12, lineHeight: 17, color: Brand.inkMuted },

  foot: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: Spacing.two,
    marginTop: Spacing.four,
    marginBottom: Spacing.three,
  },
  footText: { flex: 1, fontSize: 12, lineHeight: 17, color: Brand.inkMuted },

  error: {
    marginBottom: Spacing.two,
    padding: Spacing.two + 2,
    borderRadius: Radius.control,
    backgroundColor: Brand.redBg,
    color: Brand.red,
    fontSize: 13,
    fontWeight: '500',
  },

  save: {
    height: 48,
    borderRadius: Radius.control,
    backgroundColor: Brand.red,
    alignItems: 'center',
    justifyContent: 'center',
  },
  saveText: { color: Brand.surface, fontSize: 15, fontWeight: '600' },
  cancel: { height: 44, alignItems: 'center', justifyContent: 'center' },
  cancelText: { color: Brand.inkMuted, fontSize: 14, fontWeight: '600' },

  done: { alignItems: 'center', paddingHorizontal: Spacing.four, paddingBottom: Spacing.three },
  doneIcon: {
    width: 52,
    height: 52,
    borderRadius: Radius.full,
    backgroundColor: Brand.successBg,
    alignItems: 'center',
    justifyContent: 'center',
  },
  doneTitle: { marginTop: Spacing.three, fontSize: 16, fontWeight: '700', color: Brand.ink },
  doneBody: { marginTop: 4, fontSize: 14, color: Brand.ink, textAlign: 'center' },
  doneNote: {
    marginTop: Spacing.three,
    fontSize: 12,
    lineHeight: 18,
    color: Brand.inkMuted,
    textAlign: 'center',
  },
});
