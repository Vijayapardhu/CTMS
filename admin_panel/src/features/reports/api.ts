import { request } from '@/api/client'

/**
 * The six reports, in the shapes the service actually returns.
 *
 * They are **summaries**, not row exports — counts, rates and a couple of
 * breakdowns. The panel presents exactly that. There is no server-side export
 * endpoint (G1-3), so nothing here claims to produce an authoritative extract.
 */

export type ReportKind = 'trips' | 'occupancy' | 'fleet' | 'incidents' | 'attendance' | 'maintenance'

export const REPORT_KINDS: Array<{ kind: ReportKind; label: string; blurb: string; dated: boolean }> = [
  { kind: 'trips', label: 'Trips', blurb: 'How many ran, how many did not.', dated: true },
  { kind: 'occupancy', label: 'Occupancy', blurb: 'Seats offered against passengers carried.', dated: true },
  { kind: 'incidents', label: 'Incidents', blurb: 'What went wrong, and how fast it was answered.', dated: true },
  { kind: 'maintenance', label: 'Maintenance', blurb: 'Work raised, work finished, what it cost.', dated: true },
  { kind: 'attendance', label: 'Attendance', blurb: 'Headcount disagreements and their size.', dated: true },
  // The only one with no window: it is the fleet as it stands right now.
  { kind: 'fleet', label: 'Fleet', blurb: 'The state of the fleet at this moment.', dated: false },
]

export type Window = { from: string; to: string }

export type TripsReport = {
  window: Window
  trips: { total: number; completed: number; cancelled: number; running: number; scheduled: number }
  completion_rate: number
  cancellation_rate: number
  departed_late: number
  /** Null when nothing departed in the window — not zero, which reads as failure. */
  punctuality_rate: number | null
  auto_closed: number
}

export type OccupancyReport = {
  window: Window
  trips_measured: number
  passengers_carried: number
  seats_offered: number
  utilisation_percent: number
  by_route: Array<{ route_name: string; trips: number; passengers: number; utilisation_percent: number }>
}

export type FleetReport = {
  generated_at: string
  buses: { total: number; by_status: Record<string, number> }
  grounded_by_maintenance: number
  open_tickets: { total: number; by_priority: Record<string, number> }
  overdue_maintenance_buses: number
}

export type IncidentsReport = {
  window: Window
  total: number
  by_class: Record<string, number>
  by_type: Record<string, number>
  escalated: number
  cancelled_false_alarms: number
  unacknowledged: number
  median_acknowledgement_seconds: number | null
  life_safety_median_acknowledgement_seconds: number | null
  life_safety_worst_acknowledgement_seconds: number | null
}

export type AttendanceReport = {
  window: Window
  discrepancies: number
  open: number
  reviewed: number
  under_accounted: number
  over_accounted: number
  largest_difference: number
}

export type MaintenanceReport = {
  window: Window
  opened: number
  completed: number
  still_open: number
  total_cost: number
  median_turnaround_hours: number | null
  by_priority: Record<string, number>
}

export type AnyReport =
  | TripsReport
  | OccupancyReport
  | FleetReport
  | IncidentsReport
  | AttendanceReport
  | MaintenanceReport

export const reportKeys = {
  one: (kind: ReportKind, from: string, to: string) => ['reports', kind, from, to] as const,
}

/**
 * `from` and `to` only. There is no `date`, no route filter and no bus filter
 * on these endpoints, so the panel offers none.
 */
export const fetchReport = async (kind: ReportKind, from: string, to: string): Promise<AnyReport> =>
  (
    await request<AnyReport>(`/reports/${kind}`, {
      query: kind === 'fleet' ? {} : { from, to },
    })
  ).data

// ── presentation ───────────────────────────────────────────────────────────

export function humanise(value: string): string {
  const words = value.replace(/_/g, ' ').toLowerCase()

  return words.charAt(0).toUpperCase() + words.slice(1)
}

/** A percentage the server may legitimately have no answer for. */
export function percent(value: number | null | undefined): string {
  return value === null || value === undefined ? 'No data' : `${value}%`
}

export function duration(seconds: number | null): string {
  if (seconds === null) return 'No data'
  if (seconds < 60) return `${seconds}s`
  if (seconds < 3600) return `${Math.round(seconds / 60)} min`

  return `${(seconds / 3600).toFixed(1)} h`
}

export function shortDate(value: string): string {
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value

  return date.toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' })
}

/** This month, which is the default window and lives in the URL. */
export function defaultWindow(): { from: string; to: string } {
  const now = new Date()
  const first = new Date(now.getFullYear(), now.getMonth(), 1)
  const iso = (date: Date) => date.toISOString().slice(0, 10)

  return { from: iso(first), to: iso(now) }
}

/**
 * CSV, built here from what is on screen.
 *
 * Deliberately not called "export": there is no server-side export endpoint,
 * and a button labelled Export implies an authoritative extract of the whole
 * dataset. This is the table in front of you, and the caller says so.
 */
export function toCsv(headers: string[], rows: Array<Array<string | number | null>>): string {
  const escape = (cell: string | number | null) => {
    const text = cell === null ? '' : String(cell)

    return /[",\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text
  }

  return [headers.map(escape).join(','), ...rows.map((row) => row.map(escape).join(','))].join('\r\n')
}

export function downloadCsv(filename: string, csv: string) {
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}
