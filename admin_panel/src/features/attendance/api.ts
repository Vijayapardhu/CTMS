import { request, requestPage, type Page } from '@/api/client'

/**
 * Attendance: the disagreements between what a driver counted and what the
 * boarding record says, and the named roster behind a single stop.
 *
 * BR-266 — reviewing a discrepancy **settles** it. It does not amend either
 * figure, and the panel says so where somebody is about to press the button.
 */

export type DiscrepancyStatus = 'OPEN' | 'REVIEWED'

export type AttendanceDiscrepancy = {
  id: string
  trip_id: string
  headcount: number
  boarding_event_count: number
  difference: number
  status: DiscrepancyStatus | string
  review_note: string | null
  reviewed_at: string | null
  created_at: string
  trip?: {
    id: string
    trip_date: string
    route?: { route_name: string } | null
    driver?: { id: string; user?: { full_name?: string; first_name?: string; last_name?: string } | null } | null
  } | null
  reviewed_by?: { id: string; full_name?: string; first_name?: string; last_name?: string } | null
}

/**
 * `GET /trips/{id}/stops/{stopId}/manifest`.
 *
 * Name, registration number and whether they boarded. Nothing else — the
 * service deliberately withholds anything a stop roster does not need.
 */
export type StopManifest = {
  expected: Array<{
    student_id: string
    name: string | null
    registration_number: string | null
    boarded: boolean
  }>
  expected_count: number
  boarded_count: number
}

export const attendanceKeys = {
  discrepancies: (open: boolean, page: number) => ['attendance', 'discrepancies', open, page] as const,
  manifest: (tripId: string, stopId: string) => ['attendance', 'manifest', tripId, stopId] as const,
}

export const fetchDiscrepancies = (open: boolean, page = 1): Promise<Page<AttendanceDiscrepancy>> =>
  requestPage<AttendanceDiscrepancy>('/attendance-discrepancies', {
    query: { open: open ? 1 : undefined, page, per_page: 20 },
  })

export const fetchManifest = async (tripId: string, stopId: string): Promise<StopManifest> =>
  (await request<StopManifest>(`/trips/${tripId}/stops/${stopId}/manifest`)).data

/** `note`, min 5 — ReviewDiscrepancyRequest. Not `resolution_notes`. */
export const reviewDiscrepancy = (id: string, note: string) =>
  request(`/attendance-discrepancies/${id}/review`, { method: 'POST', body: { note } })

export const cancelTrip = (id: string, reason: string) =>
  request(`/trips/${id}/cancel`, { method: 'POST', body: { reason } })

export const reassignTrip = (id: string, body: { bus_id?: string; driver_id?: string; reason: string }) =>
  request(`/trips/${id}/reassign`, { method: 'POST', body })

export function personName(
  person: { full_name?: string; first_name?: string; last_name?: string } | null | undefined,
): string {
  if (!person) return '—'

  return person.full_name || [person.first_name, person.last_name].filter(Boolean).join(' ') || '—'
}

export function whenText(value: string | null | undefined): string {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value

  return date.toLocaleString(undefined, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}
