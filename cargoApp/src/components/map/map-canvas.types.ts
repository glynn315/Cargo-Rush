import { GeoPoint, RouteLine } from '@/models/geo/geo.model';

/**
 * The map, as the rest of the app sees it.
 *
 * Two implementations sit behind this: a `WebView` on a handset and an
 * `iframe` on the web build, because `react-native-webview` has no web
 * platform. Metro picks between them by filename — `map-canvas.web.tsx` wins
 * on web, `map-canvas.tsx` everywhere else — so nothing above this file knows
 * which one it is talking to, and the web bundle never sees the native module.
 *
 * Both draw the same page (`leaflet-page.ts`) and honour the same contract:
 * what is on the map is owned by React and pushed down, and anything the *user*
 * does to it comes back up through `onPick` or `onSelect`.
 *
 * It serves three screens now, and the differences between them are
 * `interactive` and `route`. The location picker is a map you tap to drop a pin
 * on. The carrier list is a map you read: the pins are the hauliers, tapping
 * one chooses it, and tapping the sea does nothing at all. And the driver's
 * Tracking screen is a map you *follow* — a route from A to B with the unit's
 * own position on it, where a tap should do nothing at all.
 */
export interface MapCanvasProps {
  /**
   * The pin the person owns — where they are, or the place they are choosing.
   * Null means nothing is pinned yet.
   */
  point: GeoPoint | null;

  /**
   * The user moved the pin on the map — a tap or a drag, never a change this
   * component was told about. The distinction matters: re-centring on a pin
   * somebody just placed with their thumb would move the map out from under
   * them.
   *
   * Only called when `interactive` is true.
   */
  onPick?: (lat: number, lng: number) => void;

  /**
   * Places to draw, which the person does not own — the carriers near a load.
   *
   * Held apart from `point` because they behave differently: these are read and
   * chosen from, never dragged, and the map fits itself around them so that a
   * haulier an hour away is on screen rather than off the edge of it.
   */
  markers?: MapMarker[];

  /** Which of `markers` is chosen. Drawn larger, and in the brand colour. */
  selectedId?: string | null;

  /** Somebody tapped one of `markers`. */
  onSelect?: (id: string) => void;

  /**
   * Does tapping the map drop a pin?
   *
   * True for the location picker, false for the carrier map — where a stray tap
   * on the water would otherwise place a pin the screen has no field for.
   */
  interactive?: boolean;

  /**
   * The road from A to B, drawn as a line with a marker at each end.
   *
   * Held apart from `point` and `markers` because it behaves differently again:
   * it is not chosen from and not moved, and the map fits itself around the
   * whole line so both ends and everything between them are on screen. The
   * driver's own position is `point` — the one thing on this map that moves.
   */
  route?: RouteLine | null;

  /** What the two ends of `route` are called, for their markers. */
  routeLabels?: { from: string; to: string };

  /** Fixed, because a map in an auto-height box measures zero and renders grey. */
  height?: number;
}

/** One place on the map that the person chooses from rather than moves. */
export interface MapMarker {
  id: string;
  lat: number;
  lng: number;
  /** Shown on the pin. Short — this is a label on a map, not a card. */
  label: string;
  /** The second line of the tooltip: a distance, an address. */
  detail?: string;
}

/** What the page sends up. */
export type MapMessage =
  | { type: 'ready' }
  | { type: 'pick'; lat: number; lng: number }
  | { type: 'select'; id: string };

/**
 * A stable name for a route, so a redraw can tell a new one from the same one.
 *
 * The two canvases both need it and neither should have its own idea of what
 * "the same route" means. Length and the two ends rather than the whole
 * geometry: two routes between the same points with the same number of
 * vertices are the same road, and hashing a thousand coordinate pairs on every
 * render to prove it would cost more than the redraw it saves.
 */
export function routeKey(route: RouteLine | null | undefined): string {
  if (route === null || route === undefined || route.points.length < 2) return '';

  const first = route.points[0];
  const last = route.points[route.points.length - 1];

  return [route.source, route.points.length, ...first, ...last].join(':');
}

/** Read one, or null if it is not ours — the web hears every frame on the page. */
export function parseMapMessage(raw: unknown): MapMessage | null {
  if (typeof raw !== 'string') return null;

  try {
    const data = JSON.parse(raw) as MapMessage;

    if (data?.type === 'ready') return data;
    if (data?.type === 'select' && typeof data.id === 'string' && data.id !== '') return data;
    if (data?.type === 'pick' && Number.isFinite(data.lat) && Number.isFinite(data.lng)) {
      return data;
    }

    return null;
  } catch {
    return null;
  }
}
