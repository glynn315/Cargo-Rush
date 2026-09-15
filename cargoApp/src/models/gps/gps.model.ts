/** GPS Tracking — A to B, how far along, and how fast on average. */
export interface TrackingState {
  reference: string;
  point_a: string;
  point_b: string;
  current_location: string;
  /** Where the unit is, for a map. Null until a ping carries coordinates. */
  current: { lat: number; lng: number } | null;
  /** The route as driven, oldest first — ready for a polyline. */
  path: { lat: number; lng: number; speed_kph: number; recorded_at: string }[];
  /** The trip's two map pins, so a map can frame itself in one call. */
  endpoints: {
    origin: { lat: number; lng: number } | null;
    destination: { lat: number; lng: number } | null;
  };
  progress_pct: number;
  speed_kph: number;
  /** Distance over time elapsed, not the mean of the reported speeds. */
  average_speed_kph: number;
  distance_done_m: number;
  distance_total_m: number;
  eta: string | null;
}

/**
 * What this app posts while it is moving.
 *
 * The handset is the position source that feeds the web GPS Dashboard
 * (DESIGN.md section 5.4) — this is the write that makes that true.
 */
export interface GpsPingPayload {
  trip_id: string;
  /** What a dispatcher reads — a place name, or the formatted pair. */
  location: string;
  /**
   * What a map draws.
   *
   * Sent alongside `location` rather than instead of it: the API keeps both,
   * because one is a caption and the other is a position. Optional in the type
   * only because a queued ping written by an older build of this app will not
   * have them, and those are still worth flushing when the signal returns.
   */
  lat?: number;
  lng?: number;
  speed_kph: number;
  heading?: string;
  progress_pct: number;
  distance_done_m?: number;
  /** Stamped by the handset: it may have been offline when the reading was taken. */
  recorded_at: string;
}
