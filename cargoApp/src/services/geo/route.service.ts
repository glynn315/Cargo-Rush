import { GeoPoint, RouteLine } from '@/models/geo/geo.model';
import { distanceM } from '../gps/geometry';

/** What OSRM returns. Only the fields actually read are declared. */
interface OsrmResponse {
  code: string;
  routes?: {
    distance: number;
    duration: number;
    geometry?: { coordinates: [number, number][] };
  }[];
}

/**
 * The public OSRM demo server.
 *
 * The same trade `geoService` makes with Nominatim, and worth reading in the
 * same breath: no API key, no cost, and a usage policy that asks for
 * reasonable volume rather than a fleet hammering it. Right for getting this
 * working, wrong to leave in place at scale — a firm running fifty units a day
 * should point this at a hosted routing service or its own OSRM, and only this
 * file would change.
 */
const BASE = 'https://router.project-osrm.org';

/**
 * How long to wait before falling back to the straight line.
 *
 * Short on purpose. A driver at a yard gate with one bar of signal is the
 * common case, and a map that takes fifteen seconds to appear is a map nobody
 * waits for — the straight line is a worse answer that arrives.
 */
const TIMEOUT_MS = 7000;

/**
 * The road between two points, for the map the driver follows.
 *
 * ## What this is, and what it is not
 *
 * It is a **suggested line**: the route a truck would plausibly take, drawn on
 * the map with the distance and the driving time it implies. It is what turns
 * "Bacolod → Iloilo" from two names into something a driver can look at before
 * they pull out.
 *
 * It is **not turn-by-turn navigation**, and the app does not pretend
 * otherwise. A driver who wants a voice telling them which exit to take is
 * better served by the navigation app already on their phone, which is why the
 * Tracking screen offers to hand the two points over to it. Building a second
 * navigator inside a fleet app would be a worse one.
 *
 * ## It always answers
 *
 * Every failure falls back to the straight line between the two points, with
 * the haversine distance the rest of the app already uses. That is a deliberate
 * choice about what a driver in a warehouse with no signal should see: a
 * straight line marked as an estimate tells them roughly where they are going,
 * and an error message tells them nothing. `source` says which they got, and
 * the screen says so too rather than passing a guess off as a road.
 */
export const routeService = {
  async between(origin: GeoPoint, destination: GeoPoint): Promise<RouteLine> {
    const straight = straightLine(origin, destination);

    // OSRM takes `lng,lat` — the opposite order to everything else in this app,
    // and the mistake is silent: the coordinates are both valid and the route
    // comes back from the middle of the sea.
    const path = `${origin.lng},${origin.lat};${destination.lng},${destination.lat}`;

    const params = new URLSearchParams({
      // The whole geometry rather than a simplified overview: this is drawn on
      // a map, and a route simplified to six points cuts corners across bays.
      overview: 'full',
      // GeoJSON rather than an encoded polyline, so there is no decoder here.
      // A few more bytes, one less thing to get wrong.
      geometries: 'geojson',
      alternatives: 'false',
      steps: 'false',
    });

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);

    try {
      const response = await fetch(`${BASE}/route/v1/driving/${path}?${params}`, {
        headers: { Accept: 'application/json' },
        signal: controller.signal,
      });

      if (!response.ok) return straight;

      const body = (await response.json()) as OsrmResponse;
      const route = body.routes?.[0];
      const coordinates = route?.geometry?.coordinates;

      if (body.code !== 'Ok' || route === undefined || !Array.isArray(coordinates)) {
        return straight;
      }

      // Back to `lat,lng`, which is the order Leaflet and every other line in
      // this app speaks.
      const points = coordinates
        .filter((pair) => Array.isArray(pair) && pair.length >= 2)
        .map(([lng, lat]): [number, number] => [lat, lng]);

      // Two points is not a road. Something answered, but not with a route.
      if (points.length < 2) return straight;

      return {
        points,
        distance_m: Math.round(route.distance),
        duration_s: Math.round(route.duration),
        source: 'road',
      };
    } catch {
      // Timed out, offline, or the provider is having a day. The straight line
      // is the honest answer, and it is marked as one.
      return straight;
    } finally {
      clearTimeout(timer);
    }
  },
};

/**
 * The fallback: the two points, joined.
 *
 * Distance by haversine, which under-reads a mountain road — and that is the
 * honest thing to show for it. `source: 'straight'` is what the screen reads to
 * say "as the crow flies" rather than quoting it as a driving distance.
 */
function straightLine(origin: GeoPoint, destination: GeoPoint): RouteLine {
  return {
    points: [
      [origin.lat, origin.lng],
      [destination.lat, destination.lng],
    ],
    distance_m: distanceM(origin, destination),
    // No road, so no driving time. Null rather than a guess from an average
    // speed nobody agreed to.
    duration_s: null,
    source: 'straight',
  };
}
