import { request, requestPage, type Page } from '@/api/client'
import type { LiveTrip, Trip } from '@/features/trips/api'

/**
 * Live Operations.
 *
 * The request budget is the point of this file. There is no fleet-wide live
 * endpoint (G2-1), so a naive screen would issue `2N + 1` requests per cycle —
 * 25 every ten seconds for a twelve-bus college, before a second operator
 * opens the same page.
 *
 * The frozen decision, and what these functions enforce:
 *
 *   1 × /trips?status=RUNNING           the tracked set
 *   ≤ 12 × /trips/{id}/live             positions, capped
 *   1 × /trips/{id}/eta                 the SELECTED trip only
 *   ───
 *   14 requests per 30 s, worst case
 */

/** The cap. Not a page size — a deliberate ceiling on cost. */
export const TRACKED_LIMIT = 12

/** How often positions refresh. */
export const REFRESH_MS = 30_000

export type Eta = {
  eta_at: string | null
  minutes: number | null
  basis: 'live' | 'stale' | 'scheduled' | 'arrived' | 'unknown'
  stops_away: number | null
  /** Road distance from the routing provider. Never a straight line. */
  distance_metres: number | null
  distance_is_estimate: boolean | null
}

export type TrackedTrip = { trip: Trip; live: LiveTrip | null }

export const liveKeys = {
  running: ['live', 'running'] as const,
  trip: (id: string) => ['live', 'trip', id] as const,
  eta: (id: string, stopId: string) => ['live', 'eta', id, stopId] as const,
}

export const fetchRunningTrips = (): Promise<Page<Trip>> =>
  requestPage<Trip>('/trips', { query: { status: 'RUNNING', per_page: 100 } })

export const fetchLive = async (id: string): Promise<LiveTrip> =>
  (await request<LiveTrip>(`/trips/${id}/live`)).data

/**
 * `stop_id` is required for an admin — the endpoint only defaults it for a
 * student, from their own pickup stop. Calling without one is a 422.
 */
export const fetchEta = async (tripId: string, stopId: string): Promise<Eta> =>
  (await request<Eta>(`/trips/${tripId}/eta`, { query: { stop_id: stopId } })).data

/**
 * Which trips are tracked when more are running than the cap allows.
 *
 * Ordered by scheduled departure and then by id, both stable, so the set does
 * not reshuffle every thirty seconds. A map whose buses take turns appearing
 * is worse than one that admits it is showing twelve.
 */
export function trackedSet(trips: Trip[], limit = TRACKED_LIMIT): Trip[] {
  return [...trips]
    .sort((a, b) => {
      const byTime = (a.scheduled_departure_time ?? '').localeCompare(b.scheduled_departure_time ?? '')

      return byTime !== 0 ? byTime : a.id.localeCompare(b.id)
    })
    .slice(0, limit)
}

/** The stop the bus is heading for — the first that has not been dealt with. */
export function nextStop(live: LiveTrip | null) {
  return live?.stops.find((stop) => stop.state === 'PENDING' || stop.state === 'APPROACHING') ?? null
}

/** The last stop it actually reached. */
export function currentStop(live: LiveTrip | null) {
  const done = live?.stops.filter((stop) => stop.state === 'ARRIVED' || stop.state === 'DEPARTED') ?? []

  return done.length > 0 ? done[done.length - 1] : null
}

export function stopsCompleted(live: LiveTrip | null): { done: number; total: number } {
  const stops = live?.stops ?? []

  return {
    done: stops.filter((stop) => stop.state !== 'PENDING' && stop.state !== 'APPROACHING').length,
    total: stops.length,
  }
}

/**
 * Road distance, as a manager reads it. Precision drops as the number grows —
 * a metre matters when pulling in, nothing under a kilometre matters at
 * thirty-seven of them.
 *
 * A leading `~` means the routing provider could not answer and the figure is
 * the offline estimate. Rendering an estimate as exact is the same lie as
 * rendering stale as live.
 */
export function readableDistance(metres: number | null, isEstimate: boolean | null): string | null {
  if (metres === null) return null

  const prefix = isEstimate ? '~' : ''
  if (metres < 1000) return `${prefix}${metres} m`

  const km = metres / 1000

  return km < 10 ? `${prefix}${km.toFixed(1)} km` : `${prefix}${Math.round(km)} km`
}

/** `12 min`, or the clock time when that reads better. */
export function readableEta(eta: Eta | null): string | null {
  if (!eta || eta.minutes === null) return null
  if (eta.basis === 'arrived') return 'Arrived'
  if (eta.minutes <= 0) return 'Arriving now'

  return `${eta.minutes} min`
}
