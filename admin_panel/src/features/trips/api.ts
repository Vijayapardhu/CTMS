import { request, requestPage, type Page } from '@/api/client'

/**
 * Trips, in the shape the backend actually returns.
 *
 * Models are serialised directly — there is no API Resource layer — so these
 * are the column names. `route_name` not `name`, `seating_capacity` not
 * `capacity`, `registration_number` not `plate`. Every one was read off a
 * running server.
 */

export type TripStatus = 'SCHEDULED' | 'RUNNING' | 'COMPLETED' | 'CANCELLED'

export type TripRoute = {
  id: string
  route_name: string
  route_code: string
  number_of_stops: number | null
  total_distance_km: number | null
  estimated_duration_minutes: number | null
  start_point: string | null
  end_point: string | null
}

export type TripBus = {
  id: string
  registration_number: string
  model: string | null
  seating_capacity: number | null
  status: string
  current_odometer: number | null
}

export type TripDriver = {
  id: string
  license_number: string | null
  status: string | null
  user?: { full_name?: string; first_name?: string; last_name?: string; phone_number?: string } | null
}

export type Trip = {
  id: string
  trip_date: string
  status: TripStatus
  scheduled_departure_time: string | null
  scheduled_arrival_time: string | null
  actual_departure_time: string | null
  actual_arrival_time: string | null
  booked_seat_count: number | null
  occupied_seat_count: number | null
  auto_closed: boolean
  cancellation_reason: string | null
  route?: TripRoute | null
  bus?: TripBus | null
  driver?: TripDriver | null
}

/** `GET /trips/{id}/live` — the same shape for every status. */
export type LiveStop = {
  stop_id: string
  stop_name: string
  sequence_number: number
  state: 'PENDING' | 'APPROACHING' | 'ARRIVED' | 'DEPARTED' | 'SKIPPED'
  eta_at: string | null
  arrived_at: string | null
}

export type LiveTrip = {
  trip_id: string
  status: TripStatus
  /** Null unless a position has been reported and the trip is still live. */
  position: {
    latitude: number
    longitude: number
    recorded_at: string
    age_seconds: number
    /** Server-computed. Never recalculated here. */
    is_stale: boolean
  } | null
  occupancy: { occupied: number; capacity: number } | null
  delay_minutes: number | null
  stops: LiveStop[]
}

/** `GET /routes/{id}/stops` — the planned route, for a trip that has not run. */
export type RouteStop = {
  id: string
  stop_name: string
  sequence_number: number
  estimated_arrival_minutes: number | null
  distance_from_start_km: number | null
  landmark: string | null
}

/**
 * `trip_corrections`, as the model serialises it. The columns are
 * `original_value` and `corrected_value` — not `old_value`/`new_value`, which
 * is what this was written as first and what real data corrected.
 */
export type TripCorrection = {
  id: string
  field: string
  original_value: string | null
  corrected_value: string | null
  reason: string
  created_at: string
  corrected_by?: { full_name?: string; first_name?: string; last_name?: string } | null
}

/** The full user is embedded; `full_name` is an accessor that may be absent. */
export function correctedByName(correction: TripCorrection): string {
  const user = correction.corrected_by
  if (!user) return 'Unknown'

  return user.full_name || [user.first_name, user.last_name].filter(Boolean).join(' ') || 'Unknown'
}

/**
 * The fields `TripRecoveryService::CORRECTABLE` allows. Anything else is a 422,
 * so the form offers exactly these and nothing more.
 */
export const CORRECTABLE_FIELDS = [
  { value: 'occupied_seat_count', label: 'Occupied seats' },
  { value: 'booked_seat_count', label: 'Booked seats' },
  { value: 'odometer_start', label: 'Odometer at start' },
  { value: 'odometer_end', label: 'Odometer at end' },
  { value: 'notes', label: 'Notes' },
] as const

/**
 * Filters `TripController::index` actually validates.
 *
 * There is deliberately no free-text search: the endpoint does not accept one,
 * and a search box that filtered only the page in hand would claim to search
 * the timetable while searching twenty rows.
 */
export type TripFilters = {
  date?: string
  status?: TripStatus | ''
  route_id?: string
  bus_id?: string
  driver_id?: string
  page?: number
  per_page?: number
}

export const tripKeys = {
  list: (filters: TripFilters) => ['trips', 'list', filters] as const,
  detail: (id: string) => ['trips', 'detail', id] as const,
  live: (id: string) => ['trips', 'live', id] as const,
  corrections: (id: string) => ['trips', 'corrections', id] as const,
  routeStops: (routeId: string) => ['routes', routeId, 'stops'] as const,
}

export const fetchTrips = (filters: TripFilters): Promise<Page<Trip>> =>
  requestPage<Trip>('/trips', {
    query: {
      date: filters.date,
      status: filters.status || undefined,
      route_id: filters.route_id || undefined,
      bus_id: filters.bus_id || undefined,
      driver_id: filters.driver_id || undefined,
      page: filters.page,
      per_page: filters.per_page ?? 20,
    },
  })

export const fetchTrip = async (id: string): Promise<Trip> => (await request<Trip>(`/trips/${id}`)).data

export const fetchTripLive = async (id: string): Promise<LiveTrip> =>
  (await request<LiveTrip>(`/trips/${id}/live`)).data

export const fetchTripCorrections = (id: string): Promise<Page<TripCorrection>> =>
  requestPage<TripCorrection>(`/trips/${id}/corrections`)

export const fetchRouteStops = (routeId: string): Promise<Page<RouteStop>> =>
  requestPage<RouteStop>(`/routes/${routeId}/stops`)

export const correctTrip = (id: string, body: { field: string; value: string; reason: string }) =>
  request(`/trips/${id}/corrections`, { method: 'POST', body })

// ── presentation helpers ───────────────────────────────────────────────────

export function driverName(trip: Trip): string {
  const user = trip.driver?.user
  if (!user) return '—'

  return user.full_name || [user.first_name, user.last_name].filter(Boolean).join(' ') || '—'
}

/** `08:00:00` reads as `08:00`. Seconds are noise on a timetable. */
export function clock(value: string | null | undefined): string {
  return value ? value.slice(0, 5) : '—'
}

/**
 * Occupancy as a driver's office reads it.
 *
 * Capacity comes from the bus, not from the trip, so a trip with no bus
 * assigned yet shows the count alone rather than inventing a denominator.
 */
export function occupancy(trip: Trip): string {
  const occupied = trip.occupied_seat_count ?? 0
  const capacity = trip.bus?.seating_capacity

  return capacity ? `${occupied} / ${capacity}` : `${occupied}`
}
