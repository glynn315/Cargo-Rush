import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useMemo, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { Trip } from '@/models/trip/trip.model';
import { inspectionService } from '@/services/inspection/inspection.service';
import { tripService } from '@/services/trip/trip.service';
import { Screen } from '@/components/screen';
import { Icon } from '@/components/ui/icon';
import {
  Card,
  ErrorState,
  PrimaryButton,
  SkeletonRows,
  StatusPill,
} from '@/components/ui/primitives';
import { Brand, Hit, Radius, Spacing } from '@/constants/theme';
import { fmt } from '@/constants/format';
import { useApi } from '@/hooks/use-api';
import { useMe } from '@/hooks/use-me';
import { useCurrentTrip } from '@/hooks/use-current-trip';

type Verdict = 'pass' | 'fail' | null;

/**
 * Inspect hub — DESIGN.md section 5.2. Carries two modules:
 *  - On-Boarding Trips Inspection (pre-trip checklist + AI good-to-go)
 *  - Unit Maintenance and Inspection (assigned jobs)
 *
 * Capture lives here and nowhere else: the back office reads these results but
 * never records them (section 5.4).
 *
 * ## The check is what lets a run start
 *
 * This screen used to be a form with no consequence — a driver could skip it
 * and leave. The API now refuses a departure until the unit has passed a check
 * *for that run*, so the Dashboard sends a driver here when they tap Start on
 * an unchecked run, with the trip in the route (`/inspect?trip=…`).
 *
 * Which is why a pass ends with the run leaving. The driver is standing at the
 * unit, they have just been through seven items, and making them walk back to
 * the Dashboard to press Start again would be the app forgetting what it asked
 * them here for. A fail keeps them here with the fault named, because that is
 * the screen they will use again once it is fixed.
 *
 * A pass hands them to **Tracking**, which is the map: the suggested route from
 * A to B, the distance and driving time, and the switch that starts reporting
 * their position. Cleared, started, and looking at the road — in that order,
 * which is the order the morning actually happens in.
 *
 * Opened from the tab bar with no trip in the route, it is the same screen
 * doing the same thing against whatever run they are on — which is how a check
 * mid-route, or one on an idle unit, gets recorded.
 */
export function InspectPage() {
  const me = useMe();
  const trip = useCurrentTrip();
  const checklist = useApi(inspectionService.checklist);

  /**
   * The run being checked, when the Dashboard named one.
   *
   * A confirmed run that has not left has no "current trip" to read from — it
   * is in the queue — so the id comes through the route rather than from the
   * handset's own state.
   */
  const params = useLocalSearchParams<{ trip?: string }>();
  const queuedId = typeof params.trip === 'string' && params.trip !== '' ? params.trip : null;

  const [queued, setQueued] = useState<Trip | null>(null);

  useEffect(() => {
    if (queuedId === null) {
      setQueued(null);

      return;
    }

    let cancelled = false;

    tripService
      .find(queuedId)
      .then((found) => {
        if (!cancelled) setQueued(found);
      })
      .catch(() => {
        // The run is still checkable — the id is all the submission needs.
        // Losing the reference costs a line of context, not the check.
        if (!cancelled) setQueued(null);
      });

    return () => {
      cancelled = true;
    };
  }, [queuedId]);

  /** Which run this check belongs to: the one named, else the one they are on. */
  const tripId = queuedId ?? trip.data?.id ?? null;
  const tripReference = queued?.reference ?? (queuedId === null ? trip.data?.reference : null) ?? null;

  /**
   * The unit being checked.
   *
   * The one on the run when there is one, because that is the truck in front of
   * them — and the one they hold the keys to otherwise.
   */
  const checkingVehicle = queued?.vehicle_id ?? trip.data?.vehicle_id ?? me.data?.vehicle_id ?? null;
  const checkingPlate = queued?.vehicle_plate ?? trip.data?.vehicle_plate ?? me.data?.vehicle_plate ?? null;

  // Maintenance is booked against a unit, so this waits for one to be known.
  const vehicleId = me.data?.vehicle_id ?? null;
  const jobs = useApi(
    () => (vehicleId ? inspectionService.maintenance(vehicleId) : Promise.resolve([])),
    [vehicleId],
  );

  /** True while the run is being started off the back of a pass. */
  const [leaving, setLeaving] = useState(false);

  const [verdicts, setVerdicts] = useState<Record<string, Verdict>>({});
  const [submitting, setSubmitting] = useState(false);
  const [result, setResult] = useState<string | null>(null);

  const items = checklist.data ?? [];
  const checked = useMemo(
    () => items.filter((i) => verdicts[i.key] != null).length,
    [items, verdicts],
  );
  const failed = useMemo(
    () => items.filter((i) => verdicts[i.key] === 'fail').length,
    [items, verdicts],
  );
  const complete = items.length > 0 && checked === items.length;
  const goodToGo = complete && failed === 0;

  const set = (key: string, v: Verdict) =>
    setVerdicts((prev) => ({ ...prev, [key]: prev[key] === v ? null : v }));

  /**
   * Submit the check.
   *
   * The `goodToGo` above is a live preview, so the driver can see where they
   * stand mid-checklist. The call that counts is the API's — it will not pass
   * a unit on a failed brake check however this screen feels about it — and it
   * is that answer which gets reported back here.
   */
  const submit = () => {
    if (!checkingVehicle || submitting || leaving) return;

    setSubmitting(true);
    setResult(null);

    const results = Object.fromEntries(
      items
        .filter((item) => verdicts[item.key] != null)
        .map((item) => [item.key, verdicts[item.key] === 'pass']),
    );

    inspectionService
      .submit({
        // The run this check clears. A check with no trip on it is a check of
        // the unit rather than of a departure, and clears nothing — which is
        // the right answer for one taken from the tab bar with no run going.
        trip_id: tripId,
        vehicle_id: checkingVehicle,
        driver_id: me.data?.driver_id ?? null,
        results,
      })
      .then((inspection) => {
        if (!inspection.good_to_go) {
          setResult(`Recorded — held on ${inspection.failures.join(', ')}.`);

          return;
        }

        // Cleared. If this check was for a run waiting to leave, leave on it:
        // the driver is at the unit and has just answered for it.
        if (queuedId === null) {
          setResult('Recorded — the unit is cleared to roll.');

          return;
        }

        setLeaving(true);
        setResult('Cleared — starting the run…');

        tripService
          .start(queuedId)
          .then(() => {
            /**
             * Straight to the map.
             *
             * The check is done, the unit is cleared and the run has started —
             * so the next thing the driver needs is the road: A to B, the
             * distance, the driving time, and a way into their own navigation.
             * Tracking is that screen, and it is also where they turn position
             * reporting on, which is the other thing that should happen in the
             * first minute of a run.
             *
             * `replace`, not `push`: the checklist is finished, and a back
             * gesture from the map should not return to a form that would now
             * refuse to submit.
             */
            router.replace('/tracking');
          })
          .catch((e: Error) => {
            // The check is recorded either way. Whatever stopped the departure
            // is the API's sentence, not ours to guess at.
            setResult(`Cleared, but the run did not start: ${e.message}`);
            setLeaving(false);
          });
      })
      .catch((e: Error) => setResult(e.message))
      .finally(() => setSubmitting(false));
  };

  return (
    <Screen
      title="Inspect"
      subtitle={
        tripReference
          ? `${tripReference}${checkingPlate ? ' · ' + checkingPlate : ''}`
          : 'Pre-trip check and maintenance'
      }>
      {/* Why they are here, when the Dashboard sent them. A driver who tapped
          Start and landed on a checklist needs the connection made. */}
      {queuedId !== null ? (
        <View style={styles.forRun}>
          <Icon name="route" size={16} color={Brand.blue} />
          <Text style={styles.forRunText}>
            {tripReference ?? 'This run'} cannot leave until the unit passes. It starts on its own
            once it does.
          </Text>
        </View>
      ) : null}

      {/* AI-assisted good-to-go */}
      <View
        style={[
          styles.verdict,
          {
            backgroundColor: goodToGo
              ? Brand.successBg
              : failed > 0
                ? Brand.redBg
                : Brand.tint,
          },
        ]}>
        <View
          style={[
            styles.verdictIcon,
            { backgroundColor: goodToGo ? Brand.success : failed > 0 ? Brand.red : Brand.blue },
          ]}>
          <Icon name={goodToGo ? 'check' : failed > 0 ? 'incident' : 'clipboard'} size={20} color={Brand.surface} />
        </View>
        <View style={{ flex: 1, minWidth: 0 }}>
          <Text
            style={[
              styles.verdictTitle,
              { color: goodToGo ? Brand.success : failed > 0 ? Brand.red : Brand.blue },
            ]}>
            {goodToGo ? 'Good to go' : failed > 0 ? `${failed} item needs attention` : 'Check in progress'}
          </Text>
          <Text style={styles.verdictSub}>
            AI-assisted monitoring · {checked} of {items.length} checked
          </Text>
        </View>
      </View>

      {/* On-boarding trips inspection */}
      <Card
        heading="Pre-trip inspection"
        icon="clipboard"
        hint={`${checked}/${items.length}`}
        padded={false}>
        {checklist.loading ? (
          <View style={{ padding: Spacing.three }}>
            <SkeletonRows count={5} />
          </View>
        ) : checklist.error ? (
          <ErrorState message={checklist.error.message} onRetry={checklist.reload} />
        ) : (
          items.map((item, i) => {
            const v = verdicts[item.key] ?? null;
            return (
              <View
                key={item.key}
                style={[styles.checkRow, i < items.length - 1 && styles.divider]}>
                <View style={{ flex: 1, minWidth: 0, gap: 2 }}>
                  <Text style={styles.checkLabel}>{item.label}</Text>
                  <Text style={styles.checkHint} numberOfLines={1}>
                    {item.hint}
                  </Text>
                </View>

                <View style={styles.toggle}>
                  <Pressable
                    onPress={() => set(item.key, 'pass')}
                    accessibilityRole="button"
                    accessibilityState={{ selected: v === 'pass' }}
                    accessibilityLabel={`${item.label} pass`}
                    style={[styles.toggleBtn, v === 'pass' && { backgroundColor: Brand.success }]}>
                    <Icon name="check" size={16} color={v === 'pass' ? Brand.surface : Brand.inkMuted} />
                  </Pressable>
                  <Pressable
                    onPress={() => set(item.key, 'fail')}
                    accessibilityRole="button"
                    accessibilityState={{ selected: v === 'fail' }}
                    accessibilityLabel={`${item.label} fail`}
                    style={[styles.toggleBtn, v === 'fail' && { backgroundColor: Brand.red }]}>
                    <Icon name="close" size={16} color={v === 'fail' ? Brand.surface : Brand.inkMuted} />
                  </Pressable>
                </View>
              </View>
            );
          })
        )}
      </Card>

      <View style={styles.actions}>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="Attach photo evidence"
          style={({ pressed }) => [styles.photoBtn, pressed && { backgroundColor: Brand.tint }]}>
          <Icon name="camera" size={18} color={Brand.blue} />
          <Text style={styles.photoBtnText}>Add photo</Text>
        </Pressable>
        {/* The label says what pressing it will actually do, which depends on
            two things: whether the checklist passes, and whether there is a run
            waiting on it. "Submit and start trip" on a form that only records a
            check was a promise the screen could not keep. */}
        <PrimaryButton
          label={
            submitting || leaving
              ? leaving
                ? 'Starting…'
                : 'Submitting…'
              : goodToGo && queuedId !== null
                ? 'Submit and start trip'
                : 'Submit inspection'
          }
          icon="check"
          disabled={submitting || leaving || !complete || !checkingVehicle}
          onPress={submit}
          style={{ flex: 1 }}
        />
      </View>

      {result ? (
        <Text style={styles.result} accessibilityLiveRegion="polite">
          {result}
        </Text>
      ) : null}

      {/* Unit maintenance and inspection */}
      <Card heading="Assigned maintenance" icon="fleet" padded={false}>
        {jobs.loading ? (
          <View style={{ padding: Spacing.three }}>
            <SkeletonRows count={2} />
          </View>
        ) : (
          (jobs.data ?? []).map((j, i, arr) => (
            <View key={j.id} style={[styles.jobRow, i < arr.length - 1 && styles.divider]}>
              <View style={{ flex: 1, minWidth: 0, gap: 3 }}>
                <Text style={styles.jobKind}>{j.kind}</Text>
                <Text style={styles.jobMeta}>
                  {j.vehicle_plate} · due {fmt.date(j.due_at)}
                </Text>
                <Text style={styles.jobMeta}>
                  {fmt.km(Math.max(0, j.next_service_km - j.odometer_km))} until service
                </Text>
              </View>
              <StatusPill status={j.status} />
            </View>
          ))
        )}
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  forRun: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: Spacing.two,
    padding: Spacing.three,
    borderRadius: Radius.card,
    backgroundColor: Brand.tint,
  },
  forRunText: { flex: 1, fontSize: 12, lineHeight: 17, color: Brand.ink },

  result: {
    marginTop: Spacing.three,
    fontSize: 13,
    fontWeight: '500',
    color: Brand.ink,
  },
  verdict: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three,
    padding: Spacing.three,
    borderRadius: Radius.card,
  },
  verdictIcon: {
    width: 40,
    height: 40,
    borderRadius: Radius.full,
    alignItems: 'center',
    justifyContent: 'center',
  },
  verdictTitle: { fontSize: 15, fontWeight: '700' },
  verdictSub: { marginTop: 2, fontSize: 12, color: Brand.inkMuted },

  divider: { borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: Brand.line },

  checkRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three,
    minHeight: Hit.rowTwoLine,
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.two + 2,
  },
  checkLabel: { fontSize: 14, fontWeight: '600', color: Brand.ink },
  checkHint: { fontSize: 12, color: Brand.inkMuted },

  toggle: { flexDirection: 'row', gap: Spacing.two },
  toggleBtn: {
    width: 44,
    height: 36,
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.line,
    alignItems: 'center',
    justifyContent: 'center',
  },

  actions: { flexDirection: 'row', gap: Spacing.three, alignItems: 'stretch' },
  photoBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    minHeight: 48,
    paddingHorizontal: Spacing.three,
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.blue,
    backgroundColor: Brand.surface,
  },
  photoBtnText: { fontSize: 14, fontWeight: '600', color: Brand.blue },

  jobRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.three,
    paddingHorizontal: Spacing.three,
    paddingVertical: Spacing.three,
  },
  jobKind: { fontSize: 14, fontWeight: '600', color: Brand.ink },
  jobMeta: { fontSize: 12, color: Brand.inkMuted, fontVariant: ['tabular-nums'] },
});
