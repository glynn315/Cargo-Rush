import * as Location from 'expo-location';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Pressable, StyleSheet, Text, TextInput, View, ViewStyle } from 'react-native';

import { MapCanvas } from '@/components/map/map-canvas';
import { MapMarker } from '@/components/map/map-canvas.types';
import { Icon } from '@/components/ui/icon';
import { EmptyState, InlineSpinner } from '@/components/ui/primitives';
import { fmt } from '@/constants/format';
import { Brand, Hit, Radius, Spacing } from '@/constants/theme';
import { Carrier, CarrierQuery } from '@/models/carrier/carrier.model';
import { GeoPoint } from '@/models/geo/geo.model';
import { carrierService } from '@/services/carrier/carrier.service';

/** Nominatim's neighbour: the API asks for no more than a search a second. */
const DEBOUNCE_MS = 400;

/**
 * Who should carry this load — the small map, and the list beside it.
 *
 * The screen that turns "file a request" into a choice. A shipper opens it, the
 * app reads where they are, and the hauliers near them come back nearest first
 * with what each could put on the road today. Tapping a pin and tapping a card
 * are the same act, because on a map the pin *is* the card.
 *
 * The map is small on purpose — 190px, about a fifth of a phone. It is here to
 * answer "which of these is actually near me", which a list of distances does
 * only arithmetically; the details a person decides on are in the cards
 * underneath, where they can be read without pinching anything.
 *
 * Three ways to a list, because a customer is in one of three situations. A
 * carrier keeps an address for them, so the app already knows where "near" is
 * and asks nobody for permission. There is none — the ordinary case, since
 * nothing asks for one at sign-up — so they tap **Use my location**, the one
 * route the back office does not have. Or they know exactly who they are
 * looking for, and type the name.
 *
 * Distances are straight-line and the footnote says so. A carrier 50 km across
 * a bay is not 50 km by road, and quietly implying otherwise would be a promise
 * this screen cannot keep.
 */
export function CarrierPicker({
  selected,
  onChange,
  /** Where to measure from — the customer's store, usually. */
  around = null,
  /**
   * `portal` for a signed-in shipper, which is every use of this today, and
   * `public` for the same list without a token — see `carrierService`.
   */
  source = 'portal',
  style,
}: {
  selected: Carrier | null;
  onChange: (carrier: Carrier | null) => void;
  around?: GeoPoint | null;
  source?: 'portal' | 'public';
  style?: ViewStyle;
}) {
  const [carriers, setCarriers] = useState<Carrier[]>([]);
  const [loading, setLoading] = useState(true);
  const [failure, setFailure] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [locating, setLocating] = useState(false);
  const [term, setTerm] = useState('');

  /**
   * The point the list is measured from.
   *
   * Starts at the store pin and moves only when somebody presses "use my
   * location" — a person filing a request from home for a pallet at their
   * warehouse means the warehouse, and taking the handset's position without
   * being asked would quietly answer a different question.
   */
  const [origin, setOrigin] = useState<GeoPoint | null>(around);

  /** Which fetch is the current one: two searches can land out of order. */
  const fetchId = useRef(0);

  /**
   * Follow the point we are measuring from when it moves, and only then.
   *
   * A screen that pins a place *above* this list has to change the answer to
   * "who is near me" when it does. Keyed on the coordinates rather than on the
   * object, so a re-render does not count as a move — and left alone otherwise,
   * so it never overrides a position somebody asked for with the button below.
   */
  const aroundKey = around === null ? '' : `${around.lat},${around.lng}`;
  const lastAround = useRef(aroundKey);

  useEffect(() => {
    if (aroundKey === lastAround.current) return;

    lastAround.current = aroundKey;
    if (around !== null) setOrigin(around);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [aroundKey]);

  const load = useCallback(
    (query: CarrierQuery) => {
      const ticket = ++fetchId.current;

      setLoading(true);
      setFailure(null);

      const ask = source === 'public' ? carrierService.available : carrierService.nearby;

      ask(query)
        .then((found) => {
          if (ticket !== fetchId.current) return;

          setCarriers(found);
          setLoading(false);
        })
        .catch((error: Error) => {
          if (ticket !== fetchId.current) return;

          setFailure(error.message);
          setLoading(false);
        });
    },
    [source],
  );

  // The list, and every reason it changes: where we are measuring from, and
  // what somebody has typed. Debounced, because the search box fires per
  // keystroke and the position does not.
  useEffect(() => {
    const search = term.trim();
    const query: CarrierQuery = {
      ...(origin ? { lat: origin.lat, lng: origin.lng } : {}),
      ...(search.length >= 2 ? { search } : {}),
    };

    if (search.length === 0) {
      load(query);

      return;
    }

    const timer = setTimeout(() => load(query), DEBOUNCE_MS);

    return () => clearTimeout(timer);
  }, [origin?.lat, origin?.lng, term, load]);

  /**
   * The handset's own position.
   *
   * Foreground permission only: this is a person pressing a button, not the
   * background reporting a driver's truck. A refusal is not an error — the list
   * is still there, alphabetically — so it says so and moves on.
   */
  const here = async () => {
    setLocating(true);
    setNotice(null);

    try {
      const permission = await Location.requestForegroundPermissionsAsync();

      if (!permission.granted) {
        setNotice('Location is off for this app. Search for a carrier by name instead.');

        return;
      }

      const position = await Location.getCurrentPositionAsync({
        accuracy: Location.Accuracy.Balanced,
      });

      setOrigin({
        place: 'Where you are now',
        lat: position.coords.latitude,
        lng: position.coords.longitude,
      });
    } catch {
      setNotice('Could not read your position. Search for a carrier by name instead.');
    } finally {
      setLocating(false);
    }
  };

  const markers: MapMarker[] = carriers
    .filter((carrier) => carrier.latitude !== null && carrier.longitude !== null)
    .map((carrier) => ({
      id: carrier.id,
      lat: carrier.latitude!,
      lng: carrier.longitude!,
      label: carrier.name,
      detail: carrier.distance_km === null ? undefined : `${carrier.distance_km} km`,
    }));

  const choose = (id: string) => {
    const carrier = carriers.find((row) => row.id === id) ?? null;

    // Tapping the chosen one again clears it, the way a radio row that is also
    // a toggle should — a person who picked the wrong carrier should not have
    // to find a "clear" button.
    onChange(carrier !== null && carrier.id === selected?.id ? null : carrier);
  };

  return (
    <View style={style}>
      <View style={styles.searchRow}>
        <Icon name="search" size={16} color={Brand.inkMuted} style={styles.searchIcon} />
        <TextInput
          value={term}
          onChangeText={setTerm}
          placeholder="Search a carrier by name"
          placeholderTextColor={Brand.inkMuted}
          accessibilityLabel="Search for a carrier by name"
          autoCorrect={false}
          autoCapitalize="words"
          returnKeyType="search"
          style={styles.searchInput}
        />
      </View>

      {/* Small, and above the cards: it answers "which of these is near me",
          which is the question a list of numbers answers only arithmetically. */}
      <View style={{ marginTop: Spacing.two }}>
        <MapCanvas
          point={origin}
          markers={markers}
          selectedId={selected?.id ?? null}
          onSelect={choose}
          // A tap on the sea is not a carrier, and there is no field here for
          // the pin it would otherwise drop.
          interactive={false}
          height={190}
        />
      </View>

      <View style={styles.mapFooter}>
        <Pressable
          onPress={here}
          disabled={locating}
          accessibilityRole="button"
          style={[styles.hereBtn, locating && { opacity: 0.5 }]}>
          <Icon name="map-pin" size={14} color={Brand.blue} />
          <Text style={styles.hereBtnText}>
            {locating ? 'Reading your position…' : 'Use my location'}
          </Text>
        </Pressable>

        <Text style={styles.originText} numberOfLines={1}>
          {origin ? `Near ${origin.place || 'you'}` : 'Nothing to measure from yet'}
        </Text>
      </View>

      {notice ? (
        <Text style={styles.notice} accessibilityLiveRegion="polite">
          {notice}
        </Text>
      ) : null}

      {loading ? (
        <View style={styles.loading}>
          <InlineSpinner />
          <Text style={styles.loadingText}>Finding carriers…</Text>
        </View>
      ) : failure !== null ? (
        <Text style={styles.error} accessibilityRole="alert">
          {failure}
        </Text>
      ) : carriers.length === 0 ? (
        <EmptyState
          icon="fleet"
          title="No carriers here yet"
          body={
            term.trim().length > 0
              ? 'No haulier of that name is taking requests. Try a shorter search.'
              : 'Nobody is listed near this spot. Try your location, or search by name.'
          }
        />
      ) : (
        <View style={styles.list}>
          {carriers.map((carrier) => (
            <CarrierCard
              key={carrier.id}
              carrier={carrier}
              picked={carrier.id === selected?.id}
              onPress={() => choose(carrier.id)}
            />
          ))}
        </View>
      )}

      <Text style={styles.footnote}>
        {/* Said once, at the bottom, because it qualifies every distance above
            it. Straight-line is a sort order, not a journey. */}
        Distances are straight-line, so a road trip is longer. The office confirms
        the crew and the time after you send the request.
      </Text>
    </View>
  );
}

/**
 * One haulier.
 *
 * The four things that decide it: who they are, how far, what they could send,
 * and whether this shipper has used them before. The badge goes first for the
 * last one, because "we have sent with them" is the strongest thing on the card.
 */
function CarrierCard({
  carrier,
  picked,
  onPress,
}: {
  carrier: Carrier;
  picked: boolean;
  onPress: () => void;
}) {
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="radio"
      accessibilityState={{ selected: picked }}
      accessibilityLabel={`${carrier.name}${
        carrier.distance_km === null ? '' : `, ${carrier.distance_km} kilometres away`
      }, ${carrier.vehicles_ready} units ready`}
      style={[styles.card, picked && styles.cardPicked]}>
      <View style={styles.cardHead}>
        <Text style={styles.cardName} numberOfLines={1}>
          {carrier.name}
        </Text>

        {picked ? (
          <View style={styles.tick}>
            <Icon name="check" size={13} color={Brand.surface} />
          </View>
        ) : carrier.distance_km !== null ? (
          <Text style={styles.distance}>{carrier.distance_km} km</Text>
        ) : null}
      </View>

      {carrier.linked ? (
        <View style={styles.badge}>
          <Text style={styles.badgeText}>YOU HAVE SENT WITH THEM</Text>
        </View>
      ) : null}

      {carrier.address ? (
        <View style={styles.cardRow}>
          <Icon name="map-pin" size={13} color={Brand.inkMuted} />
          <Text style={styles.cardMeta} numberOfLines={1}>
            {carrier.address}
          </Text>
        </View>
      ) : null}

      <View style={styles.cardRow}>
        <Icon name="fleet" size={13} color={Brand.inkMuted} />
        <Text style={styles.cardMeta} numberOfLines={1}>
          {carrier.vehicles_ready === 0
            ? 'No units free right now'
            : `${carrier.vehicles_ready} unit${carrier.vehicles_ready === 1 ? '' : 's'} ready · up to ${fmt.kg(
                carrier.capacity_kg,
              )}`}
        </Text>
      </View>

      {carrier.contact_phone ? (
        <Text style={styles.cardPhone}>{carrier.contact_phone}</Text>
      ) : null}

      {picked && carrier.distance_km !== null ? (
        <Text style={styles.chosenLine}>Chosen · {carrier.distance_km} km away</Text>
      ) : null}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  searchRow: { justifyContent: 'center' },
  searchIcon: { position: 'absolute', left: Spacing.three, zIndex: 1 },
  searchInput: {
    minHeight: Hit.min,
    borderRadius: Radius.control,
    borderWidth: 1,
    borderColor: Brand.line,
    paddingLeft: 40,
    paddingRight: Spacing.three,
    fontSize: 15,
    color: Brand.ink,
    backgroundColor: Brand.surface,
  },

  mapFooter: {
    marginTop: Spacing.two,
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two,
  },
  hereBtn: {
    minHeight: 38,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    paddingHorizontal: Spacing.three,
    borderRadius: Radius.full,
    borderWidth: 1,
    borderColor: Brand.line,
  },
  hereBtnText: { fontSize: 13, fontWeight: '600', color: Brand.blue },
  originText: { flex: 1, minWidth: 0, fontSize: 12, color: Brand.inkMuted, textAlign: 'right' },

  loading: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two,
    paddingVertical: Spacing.four,
  },
  loadingText: { fontSize: 13, color: Brand.inkMuted },

  list: { marginTop: Spacing.three, gap: Spacing.two },
  card: {
    gap: 4,
    padding: Spacing.three,
    borderRadius: Radius.card,
    borderWidth: 1,
    borderColor: Brand.line,
    backgroundColor: Brand.surface,
  },
  cardPicked: { borderColor: Brand.blue, backgroundColor: Brand.tint },
  cardHead: { flexDirection: 'row', alignItems: 'center', gap: Spacing.two },
  cardName: { flex: 1, minWidth: 0, fontSize: 15, fontWeight: '700', color: Brand.ink },
  distance: { fontSize: 13, fontWeight: '600', color: Brand.blue, fontVariant: ['tabular-nums'] },
  tick: {
    width: 22,
    height: 22,
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: Radius.full,
    backgroundColor: Brand.blue,
  },

  badge: {
    alignSelf: 'flex-start',
    borderRadius: Radius.full,
    backgroundColor: Brand.successBg,
    paddingHorizontal: Spacing.two,
    paddingVertical: 2,
  },
  badgeText: { fontSize: 9, fontWeight: '700', letterSpacing: 0.6, color: Brand.success },

  cardRow: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  cardMeta: { flex: 1, minWidth: 0, fontSize: 12, color: Brand.inkMuted },
  cardPhone: { fontSize: 12, fontWeight: '600', color: Brand.ink },
  chosenLine: { marginTop: 2, fontSize: 12, fontWeight: '600', color: Brand.blue },

  notice: { marginTop: Spacing.two, fontSize: 12, color: Brand.warning },
  error: { marginTop: Spacing.three, fontSize: 13, fontWeight: '500', color: Brand.red },
  footnote: { marginTop: Spacing.three, fontSize: 11, lineHeight: 16, color: Brand.inkMuted },
});
