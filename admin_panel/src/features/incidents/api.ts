import { request, requestPage, type Page } from '@/api/client'

/**
 * Incidents, in the shape `VehicleIncident` actually serialises.
 *
 * Read off a running server, not from a design document. `incident_class` and
 * `incident_type` are separate columns; `severity` is real and stored, so it is
 * shown — unlike a date filter, which the endpoint does not accept and which
 * therefore does not appear.
 */

export type IncidentStatus = 'REPORTED' | 'ACKNOWLEDGED' | 'IN_PROGRESS' | 'ESCALATED' | 'RESOLVED' | 'CLOSED'
export type IncidentClass = 'LIFE_SAFETY' | 'OPERATIONAL' | 'SERVICE'
export type IncidentSeverity = 'LOW' | 'MEDIUM' | 'HIGH' | 'CRITICAL'

export type IncidentPerson = {
  id: string
  full_name?: string
  first_name?: string
  last_name?: string
  phone_number?: string
} | null

export type IncidentNote = {
  id: string
  note: string
  created_at: string
  author?: IncidentPerson
}

export type Incident = {
  id: string
  incident_class: IncidentClass
  incident_type: string
  severity: IncidentSeverity
  status: IncidentStatus
  description: string
  latitude: number | string | null
  longitude: number | string | null
  passengers_aboard: number | null
  vehicle_can_continue: boolean | null
  reported_at: string
  acknowledged_at: string | null
  resolved_at: string | null
  escalated_at: string | null
  resolution_notes: string | null
  was_cancelled: boolean
  cancellation_note: string | null
  trip_id: string | null
  bus_id: string | null
  maintenance_ticket_id: string | null
  bus?: { id: string; registration_number: string } | null
  driver?: { id: string; user?: IncidentPerson } | null
  trip?: { id: string; trip_date: string; route?: { route_name: string } | null } | null
  reported_by?: IncidentPerson
  notes?: IncidentNote[]
  maintenance_ticket?: { id: string; status: string; issue_description: string } | null
  replacement?: { id: string; status: string } | null
}

/** `GET /incidents/types` — served rather than compiled in, so it is fetched. */
export type IncidentTypeOption = {
  type: string
  label: string
  class: IncidentClass
  class_label: string
  default_severity: IncidentSeverity
  requires_photo: boolean
}

/**
 * Exactly what `IncidentController::index` validates.
 *
 * There is no severity filter and no date range — G1-2. Offering either would
 * filter one page and quietly under-report the rest of the queue.
 */
export type IncidentFilters = {
  status?: IncidentStatus | ''
  class?: IncidentClass | ''
  type?: string
  open?: boolean
  bus_id?: string
  trip_id?: string
  page?: number
  per_page?: number
}

export const incidentKeys = {
  list: (filters: IncidentFilters) => ['incidents', 'list', filters] as const,
  detail: (id: string) => ['incidents', 'detail', id] as const,
  types: ['incidents', 'types'] as const,
}

export const fetchIncidents = (filters: IncidentFilters): Promise<Page<Incident>> =>
  requestPage<Incident>('/incidents', {
    query: {
      status: filters.status || undefined,
      class: filters.class || undefined,
      type: filters.type || undefined,
      open: filters.open ? 1 : undefined,
      bus_id: filters.bus_id || undefined,
      trip_id: filters.trip_id || undefined,
      page: filters.page,
      per_page: filters.per_page ?? 20,
    },
  })

export const fetchIncident = async (id: string): Promise<Incident> =>
  (await request<Incident>(`/incidents/${id}`)).data

export const fetchIncidentTypes = async (): Promise<IncidentTypeOption[]> =>
  (await request<IncidentTypeOption[]>('/incidents/types')).data

// ── mutations ──────────────────────────────────────────────────────────────
//
// The payload keys are the server's, verified against the controllers:
// `resolution_notes` (min 10) for resolve, `note` for both cancel (min 3,
// max 500) and add-note (min 3, max 2000). Acknowledge and close take none.

export const acknowledgeIncident = (id: string) =>
  request(`/incidents/${id}/acknowledge`, { method: 'POST', body: {} })

export const resolveIncident = (id: string, resolutionNotes: string) =>
  request(`/incidents/${id}/resolve`, { method: 'POST', body: { resolution_notes: resolutionNotes } })

export const closeIncident = (id: string) => request(`/incidents/${id}/close`, { method: 'POST', body: {} })

export const cancelIncident = (id: string, note: string) =>
  request(`/incidents/${id}/cancel`, { method: 'POST', body: { note } })

export const addIncidentNote = (id: string, note: string) =>
  request(`/incidents/${id}/notes`, { method: 'POST', body: { note } })

export const reportIncident = (body: {
  incident_type: string
  description: string
  severity?: IncidentSeverity
  trip_id?: string
  evidence_id?: string
  vehicle_can_continue?: boolean
}) => request('/incidents', { method: 'POST', body })

// ── presentation ───────────────────────────────────────────────────────────

export function personName(person: IncidentPerson | undefined): string {
  if (!person) return '—'

  return person.full_name || [person.first_name, person.last_name].filter(Boolean).join(' ') || '—'
}

/** `BRAKE_FAULT` reads as `Brake fault`. The label endpoint covers types we know. */
export function humanise(value: string | null | undefined): string {
  if (!value) return '—'
  const words = value.replace(/_/g, ' ').toLowerCase()

  return words.charAt(0).toUpperCase() + words.slice(1)
}

export function whenText(value: string | null | undefined): string {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value

  return date.toLocaleString(undefined, {
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  })
}

/**
 * The transitions `IncidentStatus::allowedTransitions` permits.
 *
 * Mirrored here only to *disable* a control and say why — the server remains
 * the authority, and a 409 from it is still rendered verbatim if this table
 * and the backend ever disagree.
 */
const ALLOWED: Record<IncidentStatus, IncidentStatus[]> = {
  REPORTED: ['ACKNOWLEDGED', 'IN_PROGRESS', 'ESCALATED', 'RESOLVED'],
  ACKNOWLEDGED: ['IN_PROGRESS', 'ESCALATED', 'RESOLVED'],
  IN_PROGRESS: ['ESCALATED', 'RESOLVED'],
  ESCALATED: ['IN_PROGRESS', 'RESOLVED'],
  RESOLVED: ['CLOSED', 'IN_PROGRESS'],
  CLOSED: [],
}

export const canTransition = (from: IncidentStatus, to: IncidentStatus): boolean =>
  ALLOWED[from]?.includes(to) ?? false

/** `IncidentStatus::isOpen()` — resolved is not open, and neither is closed. */
export const isOpen = (status: IncidentStatus): boolean => status !== 'RESOLVED' && status !== 'CLOSED'
