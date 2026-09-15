/**
 * Something that went wrong, with a time and a place.
 *
 * The driver's half of Incident Management. `cargoApp` reports one and reads
 * back its own; the office log, editing a write-up and closing one out live in
 * the web client, behind permissions no driver holds.
 */
export interface Incident {
  id: string;
  /** `INC-0231`. What the conversation with the office will be about. */
  reference: string;
  /** What happened, in the driver's words — "Tyre blowout". */
  kind: string;
  place: string;
  occurred_at: string;

  driver_id: string | null;
  driver_name: string | null;
  vehicle_id: string | null;
  vehicle_plate: string | null;
  trip_id: string | null;
  trip_reference: string | null;

  notes: string | null;
  /**
   * `pending` until somebody at the desk has looked at it.
   *
   * Not the driver's to set. A report is a statement of what happened; what is
   * being done about it is the office's answer, and a driver who could close
   * their own incident could close it before anybody had read it.
   */
  status: string;
  created_at: string;
  updated_at: string;
}

/**
 * What `POST /api/v1/incidents/report` takes.
 *
 * Three fields, because three is what somebody standing on a hard shoulder can
 * answer. No driver, no vehicle and no trip: those are the scope, stamped from
 * the token by the API, which already knows all three — and having nowhere to
 * put them is what makes it impossible to file an incident against somebody
 * else's run.
 */
export interface IncidentReport {
  kind: string;
  place: string;
  /**
   * When it happened. Omitted means now, which is what reporting from the
   * scene means — sent only for one written up later at the depot.
   */
  occurred_at?: string;
  notes?: string;
}
