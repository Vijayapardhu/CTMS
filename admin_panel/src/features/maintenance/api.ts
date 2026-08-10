import { request, requestPage, type Page } from '@/api/client'

/**
 * Maintenance tickets and preventive schedules, in the shape the models
 * serialise. `issue_description`, not `title`; `assigned_to_id`, not `assignee`.
 */

export type MaintenanceStatus = 'OPEN' | 'SCHEDULED' | 'IN_PROGRESS' | 'COMPLETED' | 'CANCELLED'
export type MaintenancePriority = 'LOW' | 'MEDIUM' | 'HIGH' | 'URGENT'

type Person = { id: string; full_name?: string; first_name?: string; last_name?: string } | null

export type MaintenanceTicket = {
  id: string
  bus_id: string
  issue_description: string
  status: MaintenanceStatus
  priority: MaintenancePriority
  assigned_to_id: string | null
  scheduled_date: string | null
  started_at: string | null
  completion_date: string | null
  estimated_cost: number | string | null
  actual_cost: number | string | null
  parts_used: string | null
  odometer_reading: number | null
  resolution_notes: string | null
  cancellation_reason: string | null
  created_at: string
  vehicle_incident_id: string | null
  vehicle_inspection_id: string | null
  bus?: { id: string; registration_number: string; status?: string } | null
  assigned_to?: Person
  opened_by?: Person
  completed_by?: Person
  incident?: { id: string; incident_type: string; status: string } | null
  inspection?: { id: string; outcome: string; inspected_at: string } | null
}

/** `preventive_maintenance_schedules`, exactly as stored. */
export type PreventiveSchedule = {
  id: string
  bus_id: string
  service_name: string
  description: string | null
  interval_days: number | null
  interval_km: number | null
  last_serviced_on: string | null
  last_serviced_odometer: number | null
  due_on: string | null
  due_at_odometer: number | null
  grace_days: number | null
  is_active: boolean
  open_ticket_id: string | null
  bus?: { id: string; registration_number: string; current_odometer?: number | null } | null
  open_ticket?: { id: string; status: MaintenanceStatus } | null
}

/** Exactly what `MaintenanceController::index` validates. Nothing more. */
export type MaintenanceFilters = {
  status?: MaintenanceStatus | ''
  priority?: MaintenancePriority | ''
  bus_id?: string
  open?: boolean
  page?: number
  per_page?: number
}

export const maintenanceKeys = {
  list: (filters: MaintenanceFilters) => ['maintenance', 'list', filters] as const,
  detail: (id: string) => ['maintenance', 'detail', id] as const,
  preventive: (due: boolean) => ['maintenance', 'preventive', due] as const,
}

export const fetchTickets = (filters: MaintenanceFilters): Promise<Page<MaintenanceTicket>> =>
  requestPage<MaintenanceTicket>('/maintenance-tickets', {
    query: {
      status: filters.status || undefined,
      priority: filters.priority || undefined,
      bus_id: filters.bus_id || undefined,
      open: filters.open ? 1 : undefined,
      page: filters.page,
      per_page: filters.per_page ?? 20,
    },
  })

export const fetchTicket = async (id: string): Promise<MaintenanceTicket> =>
  (await request<MaintenanceTicket>(`/maintenance-tickets/${id}`)).data

export const fetchPreventive = (due: boolean): Promise<Page<PreventiveSchedule>> =>
  requestPage<PreventiveSchedule>('/preventive-maintenance', { query: { due: due ? 1 : undefined, per_page: 50 } })

// ── mutations ──────────────────────────────────────────────────────────────

export const openTicket = (body: {
  bus_id: string
  issue_description: string
  priority?: MaintenancePriority
  estimated_cost?: number
  scheduled_date?: string
}) => request('/maintenance-tickets', { method: 'POST', body })

export const assignTicket = (id: string, assignedToId: string) =>
  request(`/maintenance-tickets/${id}/assign`, { method: 'POST', body: { assigned_to_id: assignedToId } })

export const scheduleTicket = (id: string, scheduledDate: string) =>
  request(`/maintenance-tickets/${id}/schedule`, { method: 'POST', body: { scheduled_date: scheduledDate } })

export const startTicket = (id: string) =>
  request(`/maintenance-tickets/${id}/start`, { method: 'POST', body: {} })

export const completeTicket = (
  id: string,
  body: { resolution_notes: string; actual_cost?: number; parts_used?: string; odometer_reading?: number },
) => request(`/maintenance-tickets/${id}/complete`, { method: 'POST', body })

export const cancelTicket = (id: string, reason: string) =>
  request(`/maintenance-tickets/${id}/cancel`, { method: 'POST', body: { reason } })

/** The second half of returning a bus to service. Deliberately its own call. */
export const changeBusStatus = (busId: string, status: string, reason?: string) =>
  request(`/buses/${busId}/status`, { method: 'PATCH', body: { status, ...(reason ? { reason } : {}) } })

export const createPreventive = (body: {
  bus_id: string
  service_name: string
  description?: string
  interval_days?: number
  interval_km?: number
}) => request('/preventive-maintenance', { method: 'POST', body })

export const deletePreventive = (id: string) =>
  request(`/preventive-maintenance/${id}`, { method: 'DELETE' })

// ── the state machine, mirrored to explain a disabled control ──────────────

const ALLOWED: Record<MaintenanceStatus, MaintenanceStatus[]> = {
  OPEN: ['SCHEDULED', 'IN_PROGRESS', 'CANCELLED'],
  SCHEDULED: ['IN_PROGRESS', 'OPEN', 'CANCELLED'],
  // Work already under way is not cancelled as though it never happened.
  IN_PROGRESS: ['COMPLETED'],
  COMPLETED: [],
  CANCELLED: [],
}

export const canTransition = (from: MaintenanceStatus, to: MaintenanceStatus): boolean =>
  ALLOWED[from]?.includes(to) ?? false

export const isTerminal = (status: MaintenanceStatus): boolean =>
  status === 'COMPLETED' || status === 'CANCELLED'

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

export function shortDate(value: string | null | undefined): string {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value

  return date.toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' })
}

/** Money as the workshop records it, with no currency invented. */
export function amount(value: number | string | null): string {
  if (value === null || value === '') return '—'
  const numeric = Number(value)

  return Number.isNaN(numeric) ? String(value) : numeric.toLocaleString()
}

export function daysUntil(value: string | null): number | null {
  if (!value) return null
  const due = new Date(value)
  if (Number.isNaN(due.getTime())) return null
  const midnight = new Date()
  midnight.setHours(0, 0, 0, 0)

  return Math.round((due.getTime() - midnight.getTime()) / 86_400_000)
}
