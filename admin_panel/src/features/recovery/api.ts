import { request, requestPage, type Page } from '@/api/client'

/**
 * Recovery: replacement vehicles and service consolidation.
 *
 * Two different acts that both take capacity out of, or put it back into, the
 * timetable — and both are staged deliberately by the backend. A replacement
 * is approved, then dispatched, then marked arrived. A consolidation is
 * proposed, approved, the passengers are told, and only then is it executed
 * (BR-363). The panel does not collapse those steps.
 */

export type ReplacementStatus = 'RECOMMENDED' | 'APPROVED' | 'REJECTED' | 'DISPATCHED' | 'ARRIVED' | 'COMPLETED'
export type ConsolidationStatus = 'PROPOSED' | 'APPROVED' | 'EXECUTED' | 'REJECTED' | 'EXPIRED'

type Person = { id: string; full_name?: string; first_name?: string; last_name?: string } | null
type BusRef = { id: string; registration_number: string } | null
type TripRef = { id: string; trip_date: string; route?: { route_name: string } | null } | null

export type Replacement = {
  id: string
  trip_id: string | null
  vehicle_incident_id: string | null
  status: ReplacementStatus
  reason: string | null
  distance_metres: number | null
  passengers_to_transfer: number | null
  rejection_reason: string | null
  approved_at: string | null
  dispatched_at: string | null
  arrived_at: string | null
  created_at: string
  trip?: TripRef
  original_bus?: BusRef
  replacement_bus?: BusRef
  incident?: { id: string; incident_type: string; status: string } | null
  approved_by?: Person
}

export type Consolidation = {
  id: string
  source_trip_id: string
  target_trip_id: string
  status: ConsolidationStatus
  reason: string | null
  source_passengers: number | null
  target_passengers: number | null
  target_capacity: number | null
  estimated_savings: number | string | null
  divergence_sequence: number | null
  rejection_reason: string | null
  passengers_notified_at: string | null
  executed_at: string | null
  expires_at: string | null
  decided_at: string | null
  created_at: string
  source_trip?: TripRef
  target_trip?: (TripRef & { bus?: BusRef }) | null
  decided_by?: Person
}

/** What the hourly analysis would propose, asked for on demand. */
export type ConsolidationCandidate = {
  source_trip_id: string
  source_route: string | null
  source_passengers: number | null
  target_trip_id: string
  target_route: string | null
  target_passengers: number | null
  target_capacity: number | null
}

export const recoveryKeys = {
  replacements: (status: string, open: boolean) => ['replacements', 'list', status, open] as const,
  replacement: (id: string) => ['replacements', 'detail', id] as const,
  consolidations: (status: string, open: boolean) => ['consolidations', 'list', status, open] as const,
  candidates: ['consolidations', 'candidates'] as const,
}

export const fetchReplacements = (status: string, open: boolean, page = 1): Promise<Page<Replacement>> =>
  requestPage<Replacement>('/replacements', {
    query: { status: status || undefined, open: open ? 1 : undefined, page, per_page: 20 },
  })

export const fetchReplacement = async (id: string): Promise<Replacement> =>
  (await request<Replacement>(`/replacements/${id}`)).data

export const fetchConsolidations = (status: string, open: boolean, page = 1): Promise<Page<Consolidation>> =>
  requestPage<Consolidation>('/consolidations', {
    query: { status: status || undefined, open: open ? 1 : undefined, page, per_page: 20 },
  })

export const fetchCandidates = async (): Promise<ConsolidationCandidate[]> =>
  (await request<ConsolidationCandidate[]>('/consolidations/candidates')).data

// ── mutations ──────────────────────────────────────────────────────────────

/** `bus_id` and `driver_id` are both optional: approving may keep the recommendation. */
export const approveReplacement = (id: string, body: { bus_id?: string; driver_id?: string } = {}) =>
  request(`/replacements/${id}/approve`, { method: 'POST', body })

export const rejectReplacement = (id: string, reason: string) =>
  request(`/replacements/${id}/reject`, { method: 'POST', body: { reason } })

export const dispatchReplacement = (id: string) =>
  request(`/replacements/${id}/dispatch`, { method: 'POST', body: {} })

export const markReplacementArrived = (id: string) =>
  request(`/replacements/${id}/arrived`, { method: 'POST', body: {} })

export const proposeConsolidation = (body: {
  source_trip_id: string
  target_trip_id: string
  reason?: string
}) => request('/consolidations', { method: 'POST', body })

export const approveConsolidation = (id: string) =>
  request(`/consolidations/${id}/approve`, { method: 'POST', body: {} })

export const rejectConsolidation = (id: string, reason: string) =>
  request(`/consolidations/${id}/reject`, { method: 'POST', body: { reason } })

export const notifyConsolidation = (id: string) =>
  request(`/consolidations/${id}/notify`, { method: 'POST', body: {} })

export const executeConsolidation = (id: string) =>
  request(`/consolidations/${id}/execute`, { method: 'POST', body: {} })

// ── state machines, mirrored to explain a disabled control ────────────────

const REPLACEMENT_NEXT: Record<ReplacementStatus, ReplacementStatus[]> = {
  RECOMMENDED: ['APPROVED', 'REJECTED'],
  APPROVED: ['DISPATCHED', 'REJECTED'],
  DISPATCHED: ['ARRIVED', 'REJECTED'],
  ARRIVED: ['COMPLETED'],
  REJECTED: [],
  COMPLETED: [],
}

export const replacementCan = (from: ReplacementStatus, to: ReplacementStatus): boolean =>
  REPLACEMENT_NEXT[from]?.includes(to) ?? false

const CONSOLIDATION_NEXT: Record<ConsolidationStatus, ConsolidationStatus[]> = {
  PROPOSED: ['APPROVED', 'REJECTED', 'EXPIRED'],
  // An approved-but-unexecuted proposal can still be rejected: the occupancy
  // that justified it may have changed while it sat.
  APPROVED: ['EXECUTED', 'REJECTED', 'EXPIRED'],
  EXECUTED: [],
  REJECTED: [],
  EXPIRED: [],
}

export const consolidationCan = (from: ConsolidationStatus, to: ConsolidationStatus): boolean =>
  CONSOLIDATION_NEXT[from]?.includes(to) ?? false

// ── presentation ───────────────────────────────────────────────────────────

export function personName(person: Person | undefined): string {
  if (!person) return '—'

  return person.full_name || [person.first_name, person.last_name].filter(Boolean).join(' ') || '—'
}

export function humanise(value: string | null | undefined): string {
  if (!value) return '—'
  const words = value.replace(/_/g, ' ').toLowerCase()

  return words.charAt(0).toUpperCase() + words.slice(1)
}

export function whenText(value: string | null | undefined): string {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value

  return date.toLocaleString(undefined, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}

/** Metres as the backend stored them. Kilometres only above a kilometre. */
export function distanceText(metres: number | null): string {
  if (metres === null) return '—'

  return metres >= 1000 ? `${(metres / 1000).toFixed(1)} km` : `${metres} m`
}
