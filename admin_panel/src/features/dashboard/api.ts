import { requestPage, type Page } from '@/api/client'

/**
 * The six dashboard sources.
 *
 * There is no dashboard endpoint (G1-1), so every tile is composed from a list
 * endpoint. Field names here were read off the running backend, not guessed —
 * incidents carry `incident_type` and `reported_at`, not `type` and
 * `occurred_at`, and getting that wrong renders an empty column rather than an
 * error.
 */

export type TripRow = {
  id: string
  status: 'SCHEDULED' | 'RUNNING' | 'COMPLETED' | 'CANCELLED'
  trip_date: string
  scheduled_departure_time: string | null
  occupied_seat_count: number | null
  route?: { route_name?: string; route_code?: string } | null
  bus?: { registration_number?: string } | null
  driver?: { user?: { full_name?: string; first_name?: string; last_name?: string } | null } | null
}

export type BusRow = {
  id: string
  registration_number: string
  status: 'AVAILABLE' | 'RUNNING' | 'MAINTENANCE' | 'BREAKDOWN' | 'OFFLINE'
}

export type IncidentRow = {
  id: string
  incident_type: string
  severity: 'LOW' | 'MEDIUM' | 'HIGH' | 'CRITICAL'
  status: string
  reported_at: string | null
  description: string | null
  bus?: { registration_number?: string } | null
}

export type TicketRow = {
  id: string
  priority: 'LOW' | 'MEDIUM' | 'HIGH' | 'URGENT'
  status: string
  issue_description: string
  bus?: { registration_number?: string } | null
}

export type ExpiringDocument = {
  id: string
  document_type?: string
  expiry_date?: string
  bus?: { registration_number?: string } | null
}

function today(): string {
  // The device's date. A college in Andhra runs on IST and the backend stores
  // UTC; the day boundary that matters is the one the operator is living in.
  const now = new Date()
  const month = `${now.getMonth() + 1}`.padStart(2, '0')
  const day = `${now.getDate()}`.padStart(2, '0')

  return `${now.getFullYear()}-${month}-${day}`
}

export const dashboardKeys = {
  tripsToday: ['dashboard', 'trips', 'today'] as const,
  runningTrips: ['dashboard', 'trips', 'running'] as const,
  fleet: ['dashboard', 'fleet'] as const,
  openIncidents: ['dashboard', 'incidents', 'open'] as const,
  openTickets: ['dashboard', 'tickets', 'open'] as const,
  expiringDocuments: ['dashboard', 'documents', 'expiring'] as const,
}

/** Counted from `pagination.total`, never from the rows in hand. */
export const fetchTripsToday = (): Promise<Page<TripRow>> =>
  requestPage<TripRow>('/trips', { query: { date: today(), per_page: 20 } })

export const fetchRunningTrips = (): Promise<Page<TripRow>> =>
  requestPage<TripRow>('/trips', { query: { date: today(), status: 'RUNNING', per_page: 100 } })

export const fetchFleet = (): Promise<Page<BusRow>> => requestPage<BusRow>('/buses', { query: { per_page: 100 } })

export const fetchOpenIncidents = (): Promise<Page<IncidentRow>> =>
  requestPage<IncidentRow>('/incidents', { query: { status: 'REPORTED', per_page: 5 } })

export const fetchOpenTickets = (): Promise<Page<TicketRow>> =>
  requestPage<TicketRow>('/maintenance-tickets', { query: { status: 'OPEN', per_page: 5 } })

/**
 * The one source with no pagination — it answers with a plain array, so its
 * count is the row count and `pagination.total` would be undefined.
 */
export const fetchExpiringDocuments = (): Promise<Page<ExpiringDocument>> =>
  requestPage<ExpiringDocument>('/fleet/documents/expiring')

export function countByStatus<T extends { status: string }>(rows: T[]): Record<string, number> {
  return rows.reduce<Record<string, number>>((counts, row) => {
    counts[row.status] = (counts[row.status] ?? 0) + 1

    return counts
  }, {})
}

/** Acronyms the backend uses that must not be title-cased into nonsense. */
const ACRONYMS = new Set(['SOS', 'GPS'])

/**
 * `SECURITY` reads better as "Security"; `SOS` does not read as "Sos".
 * CSS `capitalize` cannot tell the difference, so this does.
 */
export function readableIncidentType(raw: string): string {
  return raw
    .split('_')
    .map((word) => (ACRONYMS.has(word) ? word : word.charAt(0) + word.slice(1).toLowerCase()))
    .join(' ')
}

export function driverName(trip: TripRow): string {
  const user = trip.driver?.user
  if (!user) return '—'

  return user.full_name ?? [user.first_name, user.last_name].filter(Boolean).join(' ') ?? '—'
}
