/**
 * A place, as both clients carry it.
 *
 * The same three shapes the back office uses (`CargoUI/src/app/models/geo`),
 * because a location pinned on a phone and one pinned in the office are the
 * same record — one name, one pair of coordinates — and the API has no idea
 * which client filed it.
 */

/** A point on the earth, with the name a person reads it as. */
export interface GeoPoint {
  /** The place name. This is what the trip list and the driver's phone show. */
  place: string;
  lat: number;
  lng: number;
}

/** One result from a place search. */
export interface GeoResult extends GeoPoint {
  /** Stable id from the provider, for list keys. */
  id: string;
  /** The full address, when the short name alone would be ambiguous. */
  detail: string;
}

/**
 * A location as a trip carries it.
 *
 * The name is required and the coordinates are not: a customer who knows the
 * pickup is "Bacolod warehouse" can file the request without pinning anything,
 * and a form that refuses to send until they have found it on a map is a form
 * that gets abandoned in a warehouse with one bar of signal.
 */
export interface TripLocation {
  place: string;
  lat: number | null;
  lng: number | null;
}

/** The blank a form starts on: no name, no pin. */
export const BLANK_LOCATION: TripLocation = { place: '', lat: null, lng: null };

/** Has this location actually been put on the map? */
export function isPinned(location: TripLocation): boolean {
  return location.lat !== null && location.lng !== null;
}

/** The location as a whole point, or null when it is only a name. */
export function asPoint(location: TripLocation): GeoPoint | null {
  return isPinned(location)
    ? { place: location.place, lat: location.lat!, lng: location.lng! }
    : null;
}

/**
 * A route drawn on a map: the line, and what it adds up to.
 *
 * `points` are `[lat, lng]` pairs in the order they are driven — the shape
 * Leaflet takes, and the order everything else in this app speaks (the routing
 * provider is the one place that wants them the other way round).
 *
 * `source` is not decoration. `road` is a route a vehicle could actually
 * drive; `straight` is the two points joined, which is what a phone with no
 * signal gets, and the screen says which it is looking at rather than passing
 * one off as the other.
 */
export interface RouteLine {
  points: [number, number][];
  distance_m: number;
  /** Driving time in seconds. Null on a straight line — there is no road to time. */
  duration_s: number | null;
  source: 'road' | 'straight';
}

/** What an unnameable point is called: the coordinates themselves. */
export function coordinateLabel(lat: number, lng: number): string {
  return `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
}
