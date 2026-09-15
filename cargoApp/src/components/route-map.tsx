import { useEffect, useState } from 'react';
import { Linking, Pressable, StyleSheet, Text, View } from 'react-native';

import { MapCanvas } from '@/components/map/map-canvas';
import { Icon } from '@/components/ui/icon';
import { Card, EmptyState } from '@/components/ui/primitives';
import { fmt } from '@/constants/format';
import { Brand, Radius, Spacing } from '@/constants/theme';
import { GeoPoint, RouteLine } from '@/models/geo/geo.model';
import { CurrentTrip } from '@/models/trip/trip.model';
import { routeService } from '@/services/geo/route.service';

/**
 * The road ahead: a map from A to B, with the unit on it.
 *
 * The screen a driver looks at once the pre-trip check has cleared them and the
 * run has started. Until now the handset told them the names of two places and
 * how far apart they were; this is the shape of the journey between them, which
 * is the thing anybody actually wants before pulling out of a yard.
 *
 * ## What it draws, in order of who owns it
 *
 * **The route** is the suggested line — a road a truck could plausibly take,
 * from `routeService`. Fetched once when the screen opens and then left alone:
 * the road between two fixed points does not change while somebody drives it,
 * and re-fetching on every position update would burn a request a minute for
 * an identical answer.
 *
 * **The pin** is the unit itself, which is the one thing on this map that
 * moves. It comes from the tracking state, so it is the same position the
 * office map is drawing — a driver and a dispatcher looking at the same truck
 * see it in the same place.
 *
 * ## It is not navigation, and says so
 *
 * A line on a 240px map is orientation, not turn-by-turn directions. The button
 * hands the two points to whichever navigation app is already on the phone,
 * which is better at this than a fleet app will ever be and is the tool the
 * driver already trusts. Building a second navigator here would be building a
 * worse one.
 *
 * ## The honest cases
 *
 * A run with **no pins** gets a note rather than an empty map: the office books
 * some trips by place name alone, and a grey square would look like a fault.
 * A route that came back as a **straight line** — no signal, or the provider is
 * having a day — is drawn dashed and captioned as the crow flies, because a
 * straight line quoted as a driving distance is a lie a driver would plan
 * around.
 */
export function RouteMap({
  trip,
  here = null,
  height = 240,
}: {
  trip: CurrentTrip;
  /** Where the unit is now, from the tracking state. Null before the first ping. */
  here?: { lat: number; lng: number } | null;
  height?: number;
}) {
  const origin = pointOf(trip.origin, trip.origin_lat, trip.origin_lng);
  const destination = pointOf(trip.destination, trip.destination_lat, trip.destination_lng);

  const [route, setRoute] = useState<RouteLine | null>(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (origin === null || destination === null) return;

    let cancelled = false;

    setLoading(true);

    routeService
      .between(origin, destination)
      .then((found) => {
        if (!cancelled) setRoute(found);
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
    // The trip's two ends, not the objects: a re-render is not a new journey.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [trip.origin_lat, trip.origin_lng, trip.destination_lat, trip.destination_lng]);

  /**
   * Hand the run to the phone's own navigation.
   *
   * One URL for all three platforms: Google's directions link opens the app on
   * Android, the app or Safari on iOS, and a tab on the web build. `driving`
   * because this is a truck — a walking route through a barangay would be a
   * strange thing to offer.
   */
  const navigate = () => {
    if (origin === null || destination === null) return;

    const url =
      'https://www.google.com/maps/dir/?api=1' +
      `&origin=${origin.lat},${origin.lng}` +
      `&destination=${destination.lat},${destination.lng}` +
      '&travelmode=driving';

    // Nothing to do if the phone cannot open it: the map above is still the
    // answer, and an alert about a failed deep link helps nobody.
    Linking.openURL(url).catch(() => undefined);
  };

  if (origin === null || destination === null) {
    return (
      <Card heading="Route" icon="route">
        <EmptyState
          title="This run has no pins"
          body="It was booked by place name, so there is nothing to draw. The office can add the two points on the trip."
        />
      </Card>
    );
  }

  const straight = route?.source === 'straight';

  return (
    <Card
      heading="Suggested route"
      icon="route"
      hint={route ? fmt.metresAsKm(route.distance_m) : loading ? 'Finding a route…' : undefined}
      padded={false}>
      <MapCanvas
        point={here === null ? null : { place: trip.reference, lat: here.lat, lng: here.lng }}
        route={route}
        routeLabels={{ from: origin.place, to: destination.place }}
        // A map to read and follow, not one to drop pins on: a stray tap in a
        // moving cab must not put a marker in a field.
        interactive={false}
        height={height}
      />

      <View style={styles.body}>
        {/* Both ends in words under the map, because a label on a 240px map is
            truncated exactly when the place name matters. */}
        <View style={styles.ends}>
          <View style={styles.end}>
            <View style={[styles.dot, { backgroundColor: Brand.success }]} />
            <Text style={styles.endPlace} numberOfLines={1}>
              {origin.place}
            </Text>
          </View>
          <View style={styles.end}>
            <View style={[styles.dot, { backgroundColor: Brand.blue }]} />
            <Text style={styles.endPlace} numberOfLines={1}>
              {destination.place}
            </Text>
          </View>
        </View>

        <View style={styles.statRow}>
          <Stat
            label="DISTANCE"
            value={route ? fmt.metresAsKm(route.distance_m) : '—'}
            note={straight ? 'as the crow flies' : 'by road'}
          />
          <Stat
            label="DRIVING TIME"
            value={route?.duration_s ? fmt.duration(route.duration_s) : '—'}
            note={straight ? 'no road found' : 'at usual speeds'}
          />
        </View>

        {/* Said in words as well as in dashes on the map: a driver planning a
            day around a straight-line distance would plan it short. */}
        {straight ? (
          <Text style={styles.caption}>
            No road route came back — the line is the direct distance, which a truck cannot
            drive. Try again when you have signal.
          </Text>
        ) : null}

        <Pressable
          accessibilityRole="button"
          accessibilityLabel="Open this route in maps"
          onPress={navigate}
          style={({ pressed }) => [styles.navBtn, pressed && { backgroundColor: Brand.tint }]}>
          <Icon name="map-pin" size={16} color={Brand.blue} />
          <Text style={styles.navBtnText}>Open in maps for directions</Text>
          <Icon name="chevron-right" size={16} color={Brand.blue} />
        </Pressable>
      </View>
    </Card>
  );
}

function Stat({ label, value, note }: { label: string; value: string; note?: string }) {
  return (
    <View style={{ flex: 1, minWidth: 0 }}>
      <Text style={styles.statLabel}>{label}</Text>
      <Text style={styles.statValue}>{value}</Text>
      {note ? <Text style={styles.statNote}>{note}</Text> : null}
    </View>
  );
}

/** A trip end as a point, or null when it was booked by name alone. */
function pointOf(place: string, lat: number | null, lng: number | null): GeoPoint | null {
  return lat === null || lng === null ? null : { place, lat, lng };
}

const styles = StyleSheet.create({
  body: { padding: Spacing.three, gap: Spacing.three },

  ends: { gap: Spacing.two },
  end: { flexDirection: 'row', alignItems: 'center', gap: Spacing.two },
  dot: { width: 8, height: 8, borderRadius: Radius.full },
  endPlace: { flex: 1, fontSize: 13, fontWeight: '600', color: Brand.ink },

  statRow: { flexDirection: 'row', gap: Spacing.three },
  statLabel: { fontSize: 10, fontWeight: '600', letterSpacing: 0.6, color: Brand.inkMuted },
  statValue: { marginTop: 2, fontSize: 18, fontWeight: '700', color: Brand.ink },
  statNote: { fontSize: 11, color: Brand.inkMuted },

  caption: { fontSize: 12, lineHeight: 17, color: Brand.warning },

  navBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two,
    minHeight: 44,
    paddingHorizontal: Spacing.three,
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.line,
  },
  navBtnText: { flex: 1, fontSize: 14, fontWeight: '600', color: Brand.blue },
});
