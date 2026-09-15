import { Incident, IncidentReport } from '@/models/incident/incident.model';

import { api } from '../shared/api.service';

/**
 * Incidents, the driver's half.
 *
 * Two endpoints, both scoped to whoever is holding the phone. Neither takes a
 * driver, a vehicle or a trip: the API stamps all three from the token, so
 * there is no id here for a client to get wrong and none for anybody to change.
 *
 * The office's own log — every incident, editable, closable — is `incidents`
 * proper in the web client, behind permissions no driver holds. What a driver
 * has is this: say what happened, and see what you have said.
 */
export const incidentService = {
  /**
   * Report one. Lands as `pending` and puts a row on the office's feed.
   *
   * Comes back as the incident, reference and all, because the reference is
   * what the phone call that follows will be about.
   */
  report(report: IncidentReport): Promise<Incident> {
    return api.post<Incident>('incidents/report', report);
  },

  /** What this driver has reported, newest first. */
  mine(): Promise<Incident[]> {
    return api.get<Incident[]>('incidents/mine');
  },
};
