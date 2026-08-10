import { request, requestPage, type Page } from '@/api/client'

/** Drivers and students, in the shapes the models serialise. */

export type DriverStatus = 'AVAILABLE' | 'ON_TRIP' | 'LEAVE' | 'OFF_DUTY'
export type StudentStatus = 'ACTIVE' | 'INACTIVE' | 'SUSPENDED'

type Person = {
  id?: string
  full_name?: string
  first_name?: string
  last_name?: string
  email?: string
  phone_number?: string
} | null

export type Driver = {
  id: string
  user_id: string
  license_number: string | null
  license_class: string | null
  license_expiry_date: string | null
  status: DriverStatus
  assigned_bus_id: string | null
  total_trips?: number | null
  user?: Person
  assigned_bus?: { id: string; registration_number: string } | null
}

export type Student = {
  id: string
  user_id: string
  registration_number: string | null
  department: string | null
  year_of_study: number | null
  status: StudentStatus
  route_id: string | null
  pickup_stop_id: string | null
  has_valid_ticket: boolean | null
  ticket_expiry_date: string | null
  user?: Person
  route?: { id: string; route_name: string } | null
  pickup_stop?: { id: string; stop_name: string } | null
}

export type DriverFilters = { status?: string; assignable?: boolean; search?: string; page?: number }
export type StudentFilters = {
  status?: string
  route_id?: string
  unassigned?: boolean
  search?: string
  page?: number
}

export const peopleKeys = {
  drivers: (filters: DriverFilters) => ['drivers', 'list', filters] as const,
  students: (filters: StudentFilters) => ['students', 'list', filters] as const,
}

export const fetchDrivers = (filters: DriverFilters): Promise<Page<Driver>> =>
  requestPage<Driver>('/drivers', {
    query: {
      status: filters.status || undefined,
      assignable: filters.assignable ? 1 : undefined,
      search: filters.search || undefined,
      page: filters.page,
      per_page: 20,
    },
  })

export const fetchStudents = (filters: StudentFilters): Promise<Page<Student>> =>
  requestPage<Student>('/students', {
    query: {
      status: filters.status || undefined,
      route_id: filters.route_id || undefined,
      unassigned: filters.unassigned ? 1 : undefined,
      search: filters.search || undefined,
      page: filters.page,
      per_page: 20,
    },
  })

export const setDriverStatus = (id: string, status: DriverStatus) =>
  request(`/drivers/${id}/status`, { method: 'PATCH', body: { status } })

export const assignDriverBus = (id: string, busId: string) =>
  request(`/drivers/${id}/assign-bus`, { method: 'POST', body: { bus_id: busId } })

export const setStudentStatus = (id: string, status: StudentStatus) =>
  request(`/students/${id}/status`, { method: 'PATCH', body: { status } })

export const assignStudentTransport = (
  id: string,
  body: { route_id: string; pickup_stop_id: string; dropoff_stop_id?: string; capacity_override_reason?: string },
) => request(`/students/${id}/assign-transport`, { method: 'POST', body })

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

/** Days until a licence lapses. Negative means it already has. */
export function daysUntil(value: string | null): number | null {
  if (!value) return null
  const due = new Date(value)
  if (Number.isNaN(due.getTime())) return null
  const midnight = new Date()
  midnight.setHours(0, 0, 0, 0)

  return Math.round((due.getTime() - midnight.getTime()) / 86_400_000)
}
