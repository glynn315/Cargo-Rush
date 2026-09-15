import { StatusValue } from '../shared/status.model';

/** A point on the earth. The one shape every map feature here passes around. */
export interface LatLng {
  lat: number;
  lng: number;
}

/**
 * GPS Dashboard — one live unit, trip and latest ping flattened into the row
 * the map and the table both read.
 */
export interface GpsUnit {
  id: string;
  reference: string;
  vehicle_plate: string | null;
  driver_name: string | null;
  /** What a dispatcher reads: a place name, or the formatted pair. */
  location: string;
  /**
   * Where to draw the marker.
   *
   * Falls back to the trip's origin pin for a unit that has been dispatched
   * and has not reported yet — it is at the yard, which is true. Null when
   * nobody pinned the origin either: that row belongs in the table with no
   * marker, rather than a truck dropped at (0, 0) in the Gulf of Guinea.
   */
  lat: number | null;
  lng: number | null;
  /**
   * Whether that position is a real report or the origin standing in for one.
   *
   * Worth drawing differently. A dashboard that cannot tell them apart shows a
   * stale yard pin with the same confidence as a live fix.
   */
  has_fix: boolean;
  speed_kph: number;
  heading: string;
  progress_pct: number;
  eta: string | null;
  status: StatusValue;
  /** ISO. The client renders "40 sec ago"; the API never formats. */
  updated_at: string;
}

/** GPS Tracking (mobile) — A to B, progress, and average speed. */
export interface TrackingState {
  reference: string;
  point_a: string;
  point_b: string;
  current_location: string;
  /** Where the unit is. Null until a ping carries coordinates. */
  current: LatLng | null;
  /** The route as actually driven, oldest first — hand it to a polyline. */
  path: (LatLng & { speed_kph: number; recorded_at: string })[];
  /** The trip's two map pins, so a map can frame itself without a second call. */
  endpoints: { origin: LatLng | null; destination: LatLng | null };
  progress_pct: number;
  speed_kph: number;
  /** Distance over time elapsed, not the mean of reported speeds. */
  average_speed_kph: number;
  distance_done_m: number;
  distance_total_m: number;
  eta: string | null;
}

/** What the handset posts while it is moving. */
export interface GpsPingPayload {
  trip_id: string;
  /** What a dispatcher reads. The coordinates below are what a map draws. */
  location: string;
  lat?: number;
  lng?: number;
  speed_kph: number;
  heading?: string;
  progress_pct: number;
  distance_done_m?: number;
  recorded_at: string;
}
