import { request, requestPage, type Page } from '@/api/client'

/**
 * The fleet, in the shape the backend returns.
 *
 * `BusStatus` is the backend's enum, all five values, and the panel maps only
 * these — no frontend state is invented, and readiness is emphatically not
 * inferred from `status`. A bus can be `AVAILABLE` and still not cleared for
 * service, which is exactly what the readiness endpoint exists to say.
 */
export type BusStatus = 'AVAILABLE' | 'RUNNING' | 'MAINTENANCE' | 'BREAKDOWN' | 'OFFLINE'

export type Bus = {
  id: string
  registration_number: string
  vehicle_name: string | null
  model: string | null
  seating_capacity: number | null
  status: BusStatus
  current_odometer: number | null
  mileage: number | null
  fuel_type: string | null
  year_of_manufacture: number | null
  last_maintenance_date: string | null
  next_maintenance_due: string | null
  remarks: string | null
}

/** `GET /buses/{id}/service-readiness` — the authority on whether it may run. */
export type ServiceReadiness = {
  cleared: boolean
  /** Every reason, not the first. Rendered in the server's own words. */
  reasons: string[]
  inspection: Inspection | null
}

export type InspectionItem = {
  id: string
  item: string
  passed: boolean
  notes: string | null
}

export type Inspection = {
  id: string
  outcome: 'PASSED' | 'PASSED_WITH_DEFECTS' | 'FAILED'
  odometer_reading: number | null
  inspected_on: string | null
  inspected_at: string | null
  notes: string | null
  maintenance_ticket_id: string | null
  items?: InspectionItem[]
  driver?: { user?: { full_name?: string; first_name?: string; last_name?: string } | null } | null
}

export type BusDocument = {
  id: string
  bus_id: string
  document_type: string
  document_number: string | null
  issuing_authority: string | null
  issued_on: string | null
  expires_on: string | null
  /** Private storage. Never turned into a public URL here. */
  file_path: string | null
  notes: string | null
  bus?: { registration_number?: string } | null
}

/** `/buses/{id}/documents` answers an object, not a list. */
export type BusDocuments = {
  documents: BusDocument[]
  compliance: { is_compliant: boolean; missing_or_expired: string[] }
}

/** Filters `BusController::index` actually validates. */
export type FleetFilters = {
  status?: BusStatus | ''
  search?: string
  page?: number
  per_page?: number
}

export const fleetKeys = {
  list: (filters: FleetFilters) => ['fleet', 'list', filters] as const,
  bus: (id: string) => ['fleet', 'bus', id] as const,
  readiness: (id: string) => ['fleet', 'readiness', id] as const,
  inspections: (id: string) => ['fleet', 'inspections', id] as const,
  documents: (id: string) => ['fleet', 'documents', id] as const,
  expiring: ['fleet', 'documents', 'expiring'] as const,
}

export const fetchBuses = (filters: FleetFilters): Promise<Page<Bus>> =>
  requestPage<Bus>('/buses', {
    query: {
      status: filters.status || undefined,
      search: filters.search || undefined,
      page: filters.page,
      per_page: filters.per_page ?? 20,
    },
  })

export const fetchBus = async (id: string): Promise<Bus> => (await request<Bus>(`/buses/${id}`)).data

export const fetchReadiness = async (id: string): Promise<ServiceReadiness> =>
  (await request<ServiceReadiness>(`/buses/${id}/service-readiness`)).data

export const fetchInspections = (id: string): Promise<Page<Inspection>> =>
  requestPage<Inspection>(`/buses/${id}/inspections`)

export const fetchDocuments = async (id: string): Promise<BusDocuments> =>
  (await request<BusDocuments>(`/buses/${id}/documents`)).data

export const fetchExpiringDocuments = (): Promise<Page<BusDocument>> =>
  requestPage<BusDocument>('/fleet/documents/expiring')

// ── presentation ───────────────────────────────────────────────────────────

export function odometer(bus: Bus): string {
  return bus.current_odometer == null ? '—' : `${bus.current_odometer.toLocaleString()} km`
}

export function capacity(bus: Bus): string {
  return bus.seating_capacity == null ? '—' : `${bus.seating_capacity} seats`
}

/** `INSURANCE` reads as `Insurance`; `POLLUTION_CERTIFICATE` as three words. */
export function readableDocumentType(raw: string): string {
  return raw
    .split('_')
    .map((word) => word.charAt(0) + word.slice(1).toLowerCase())
    .join(' ')
}

export function inspectorName(inspection: Inspection): string {
  const user = inspection.driver?.user
  if (!user) return '—'

  return user.full_name || [user.first_name, user.last_name].filter(Boolean).join(' ') || '—'
}

export function shortDate(value: string | null | undefined): string {
  if (!value) return '—'
  const parsed = new Date(value)

  return Number.isNaN(parsed.valueOf())
    ? '—'
    : parsed.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
}

/** Whole days until expiry. Negative means it has already lapsed. */
export function daysUntil(value: string | null | undefined): number | null {
  if (!value) return null
  const parsed = new Date(value)
  if (Number.isNaN(parsed.valueOf())) return null

  return Math.ceil((parsed.valueOf() - Date.now()) / 86_400_000)
}
