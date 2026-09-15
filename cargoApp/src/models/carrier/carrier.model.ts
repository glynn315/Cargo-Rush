import { QueryParams } from '@/models/shared/envelope.model';

/**
 * A haulier, as the firm choosing one sees it.
 *
 * Deliberately short. This is read by a shipper about a company they may never
 * have dealt with, so it carries only what a haulier advertises: who they are,
 * where the yard is, what they could put on the road today, and the number to
 * ring. Nothing about that company's work, its rates or its other customers is
 * in the record at all — see `CarrierResource` on the API side.
 *
 * Three of the fields are true only in relation to whoever is asking: the
 * distance, and whether this shipper already has an account there.
 */
export interface Carrier {
  id: string;
  name: string;
  code: string;
  /** A 64px square PNG, or null — in which case the card draws initials. */
  logo_url: string | null;
  address: string | null;
  contact_phone: string | null;

  /** The yard. Always set: a carrier with no pin is not on this list. */
  latitude: number | null;
  longitude: number | null;

  /**
   * Kilometres from the point the app asked about, or null when it asked
   * without one — in which case the list is alphabetical and the screen says
   * so rather than printing "0.0 km" against everybody.
   *
   * Straight-line. It is a sort order, not an ETA, and the screen wording is
   * careful about the difference.
   */
  distance_km: number | null;

  /** Units not in the workshop and not retired. What could be sent out. */
  vehicles_ready: number;
  /** What those units can carry between them, in kilograms. */
  capacity_kg: number;

  /** Has this shipper sent with them before? The list leads with these. */
  linked: boolean;
}

/**
 * What to ask the directory for.
 *
 * Every field is optional, including the position: a customer who declined the
 * location prompt still gets a list — alphabetically — because a haulier they
 * can ring is more use than a prompt they already said no to.
 */
export interface CarrierQuery extends QueryParams {
  lat?: number;
  lng?: number;
  /** Kilometres. The API defaults to 150 and refuses more than 1000. */
  radius_km?: number;
  search?: string;
}
