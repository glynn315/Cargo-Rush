/**
 * The API envelope — DESIGN.md section 7.1.
 *
 * Identical to the web client's, on purpose: one contract, no mobile-only
 * response shapes (section 5.3).
 */
export interface Envelope<T> {
  data: T;
  meta?: Record<string, unknown>;
}

export interface ListMeta {
  page: number;
  per_page: number;
  total: number;
}

export interface ApiError {
  message: string;
  errors?: Record<string, string[]>;
}

/**
 * Anything that can ride in a query string.
 *
 * The shape `api` actually serialises, and the reason it exists as a named type
 * is that not every read is a list: the carrier directory is asked with a
 * latitude, a longitude and a radius, none of which belong in `ListQuery`
 * beside `driver_id`. An open record with a closed value type keeps both honest
 * without the API service having to know about either.
 */
export type QueryParams = {
  [key: string]: string | number | boolean | string[] | number[] | null | undefined;
};

/** Query parameters every list endpoint understands. */
export interface ListQuery extends QueryParams {
  page?: number;
  per_page?: number;
  status?: string | string[];
  search?: string;
  from?: string;
  to?: string;
  driver_id?: string;
  vehicle_id?: string;
}
