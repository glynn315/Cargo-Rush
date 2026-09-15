import { Carrier, CarrierQuery } from '@/models/carrier/carrier.model';

import { api } from '../shared/api.service';

/**
 * Which hauliers could take this load.
 *
 * Two endpoints, and the difference is who is asking rather than what comes
 * back:
 *
 *   `nearby()` is the list for somebody signed in, plus which of them they
 *   already deal with (`linked`). Every screen in the app uses this one:
 *   choosing a carrier happens per load, on the request form, by which point
 *   there is always a token. It is refused — 403 — for an account the office
 *   created: that customer is one haulier's, and this list is not theirs to
 *   browse.
 *
 *   `available()` is the same list with no token at all. Nothing in the app
 *   calls it today — sign-up asks for a name and a password and nothing else —
 *   and it is kept because the endpoint is the platform's shop window, for
 *   whoever wants to see who hauls near them before they have an account.
 *
 * Neither takes a company id. The list is a query about a place, and the API
 * decides what a place answers with.
 */
export const carrierService = {
  /** The signed-in shipper's list: nearest first, theirs flagged. */
  nearby(query: CarrierQuery = {}): Promise<Carrier[]> {
    return api.get<Carrier[]>('portal/carriers', query);
  },

  /** The same list before anybody has an account — the sign-up screen's. */
  available(query: CarrierQuery = {}): Promise<Carrier[]> {
    return api.get<Carrier[]>('carriers', query);
  },
};
