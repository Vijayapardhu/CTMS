import { useQuery } from '@tanstack/react-query'
import { NavLink, useParams, useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { EmptyState, Field, FieldGrid, LoadFailed, LoadingRows, Panel } from '@/components/Panel'
import { ActionButton } from '@/components/operations'
import {
  REPORT_KINDS,
  defaultWindow,
  downloadCsv,
  duration,
  fetchReport,
  humanise,
  percent,
  reportKeys,
  shortDate,
  toCsv,
  type AttendanceReport,
  type FleetReport,
  type IncidentsReport,
  type MaintenanceReport,
  type OccupancyReport,
  type ReportKind,
  type TripsReport,
} from './api'

/**
 * A15 Reports.
 *
 * Six summaries, each from one endpoint, each with the window the endpoint
 * actually accepts — `from` and `to`, and nothing else. There is no route
 * filter and no bus filter here because those endpoints do not have them.
 *
 * The fleet report has no window at all: it is the fleet as it stands right
 * now, and the screen says so rather than showing a date range that does not
 * apply.
 */
export function ReportsScreen() {
  const { kind: routeKind } = useParams()
  const [params, setParams] = useSearchParams()

  const kind: ReportKind = (REPORT_KINDS.find((entry) => entry.kind === routeKind)?.kind ?? 'trips') as ReportKind
  const spec = REPORT_KINDS.find((entry) => entry.kind === kind)!

  const fallback = defaultWindow()
  const from = params.get('from') ?? fallback.from
  const to = params.get('to') ?? fallback.to

  const report = useQuery({
    queryKey: reportKeys.one(kind, from, to),
    queryFn: () => fetchReport(kind, from, to),
  })

  const setWindow = (next: { from?: string; to?: string }) => {
    const merged = new URLSearchParams(params)
    merged.set('from', next.from ?? from)
    merged.set('to', next.to ?? to)
    setParams(merged, { replace: true })
  }

  return (
    <>
      <PageHeader
        title="Reports"
        subtitle={spec.blurb}
      />

      <nav className="mb-lg flex flex-wrap gap-xs border-b border-outline" aria-label="Reports">
        {REPORT_KINDS.map((entry) => (
          <NavLink
            key={entry.kind}
            to={{ pathname: `/reports/${entry.kind}`, search: entry.dated ? `?from=${from}&to=${to}` : '' }}
            className={({ isActive }) =>
              `h-[var(--size-toolbar)] px-lg text-body leading-[var(--size-toolbar)] ${
                isActive || (entry.kind === 'trips' && !routeKind)
                  ? 'border-b-2 border-primary font-semibold text-primary'
                  : 'text-on-surface-muted'
              }`
            }
          >
            {entry.label}
          </NavLink>
        ))}
      </nav>

      <div className="mb-lg flex flex-wrap items-end gap-md rounded-md border border-outline bg-surface p-md">
        {spec.dated ? (
          <>
            <label className="flex flex-col gap-xs">
              <span className="text-label font-medium text-on-surface-muted uppercase">From</span>
              <input
                type="date"
                value={from}
                onChange={(event) => setWindow({ from: event.target.value })}
                className="h-[var(--size-control)] rounded-sm border border-outline bg-surface px-md text-body"
              />
            </label>
            <label className="flex flex-col gap-xs">
              <span className="text-label font-medium text-on-surface-muted uppercase">To</span>
              <input
                type="date"
                value={to}
                onChange={(event) => setWindow({ to: event.target.value })}
                className="h-[var(--size-control)] rounded-sm border border-outline bg-surface px-md text-body"
              />
            </label>
          </>
        ) : (
          <p className="text-body text-on-surface-muted">
            This report has no date range — it is the fleet as it stands right now.
          </p>
        )}

        {report.isFetching && <span className="text-label text-on-surface-muted">Running…</span>}
      </div>

      {report.isPending && <LoadingRows rows={4} />}

      {report.isError && (
        <Panel>
          <LoadFailed what="this report" error={report.error} onRetry={() => void report.refetch()} />
        </Panel>
      )}

      {report.data && kind === 'trips' && <Trips report={report.data as TripsReport} />}
      {report.data && kind === 'occupancy' && <Occupancy report={report.data as OccupancyReport} />}
      {report.data && kind === 'fleet' && <Fleet report={report.data as FleetReport} />}
      {report.data && kind === 'incidents' && <Incidents report={report.data as IncidentsReport} />}
      {report.data && kind === 'attendance' && <Attendance report={report.data as AttendanceReport} />}
      {report.data && kind === 'maintenance' && <Maintenance report={report.data as MaintenanceReport} />}
    </>
  )
}

/**
 * The download.
 *
 * "Download this table", never "Export": there is no server-side export
 * endpoint, and a button called Export implies an authoritative extract of the
 * whole dataset (G1-3). The caption says exactly what the file contains.
 */
function DownloadTable({
  filename,
  headers,
  rows,
  caption,
}: {
  filename: string
  headers: string[]
  rows: Array<Array<string | number | null>>
  caption: string
}) {
  return (
    <div className="flex flex-wrap items-center gap-md border-t border-outline px-lg py-md">
      <ActionButton
        label="Download this table"
        icon="download"
        disabled={rows.length === 0}
        title={rows.length === 0 ? 'There is nothing in this table to download.' : undefined}
        onClick={() => downloadCsv(filename, toCsv(headers, rows))}
      />
      <p className="text-label text-on-surface-muted">{caption}</p>
    </div>
  )
}

/**
 * A single figure from a report.
 *
 * Deliberately not C5 MetricCard: that component's whole job is the difference
 * between `0` and "could not find out" for a live operational count. These are
 * summary figures that are already strings — percentages the server may have
 * no answer for — and borrowing the card would blur a distinction worth
 * keeping sharp.
 */
function Figure({ label, value, tone = 'neutral' }: { label: string; value: string; tone?: 'neutral' | 'positive' | 'caution' | 'critical' }) {
  const colour = {
    neutral: 'text-on-surface',
    positive: 'text-positive',
    caution: 'text-caution',
    critical: 'text-critical',
  }[tone]

  return (
    <div className="rounded-md border border-outline bg-surface p-lg">
      <p className="text-label font-medium text-on-surface-muted uppercase">{label}</p>
      <p className={`mt-xs text-display font-semibold ${colour}`}>{value}</p>
    </div>
  )
}

function WindowNote({ window }: { window: { from: string; to: string } }) {
  return (
    <p className="mb-lg text-label text-on-surface-muted">
      {shortDate(window.from)} to {shortDate(window.to)}
    </p>
  )
}

function Breakdown({
  title,
  entries,
  filename,
  noun,
}: {
  title: string
  entries: Record<string, number>
  filename: string
  noun: string
}) {
  const rows = Object.entries(entries)

  return (
    <Panel title={title} className="mt-lg">
      {rows.length === 0 ? (
        <EmptyState icon="reports" title={`No ${noun} in this range`} />
      ) : (
        <ul className="p-lg">
          {rows.map(([key, value]) => (
            <li key={key} className="flex items-baseline gap-md border-b border-outline py-sm text-body last:border-0">
              <span>{humanise(key)}</span>
              <span className="ml-auto font-mono font-semibold">{value}</span>
            </li>
          ))}
        </ul>
      )}
      <DownloadTable
        filename={filename}
        headers={[noun, 'count']}
        rows={rows.map(([key, value]) => [humanise(key), value])}
        caption={`${rows.length} rows — exactly what is on screen.`}
      />
    </Panel>
  )
}

function Trips({ report }: { report: TripsReport }) {
  return (
    <>
      <WindowNote window={report.window} />
      <div className="grid gap-lg sm:grid-cols-2 lg:grid-cols-4">
        <Figure label="Trips" value={String(report.trips.total)} />
        <Figure label="Completed" value={String(report.trips.completed)} tone="positive" />
        <Figure label="Cancelled" value={String(report.trips.cancelled)} tone={report.trips.cancelled > 0 ? 'caution' : 'neutral'} />
        <Figure label="Completion rate" value={percent(report.completion_rate)} />
      </div>

      <Panel title="Detail" className="mt-lg">
        <FieldGrid columns={4}>
          <Field label="Running">{report.trips.running}</Field>
          <Field label="Scheduled">{report.trips.scheduled}</Field>
          <Field label="Cancellation rate">{percent(report.cancellation_rate)}</Field>
          <Field label="Departed late">{report.departed_late}</Field>
          {/* Null is "nothing departed in this window", not zero per cent. */}
          <Field label="Punctuality">{percent(report.punctuality_rate)}</Field>
          <Field label="Closed automatically">{report.auto_closed}</Field>
        </FieldGrid>
        <DownloadTable
          filename={`ctms-trips-${report.window.from.slice(0, 10)}.csv`}
          headers={['measure', 'value']}
          rows={[
            ['Trips', report.trips.total],
            ['Completed', report.trips.completed],
            ['Cancelled', report.trips.cancelled],
            ['Running', report.trips.running],
            ['Scheduled', report.trips.scheduled],
            ['Completion rate %', report.completion_rate],
            ['Cancellation rate %', report.cancellation_rate],
            ['Departed late', report.departed_late],
            ['Punctuality rate %', report.punctuality_rate],
            ['Closed automatically', report.auto_closed],
          ]}
          caption="The figures above, for this window only."
        />
      </Panel>
    </>
  )
}

function Occupancy({ report }: { report: OccupancyReport }) {
  return (
    <>
      <WindowNote window={report.window} />
      <div className="grid gap-lg sm:grid-cols-2 lg:grid-cols-4">
        <Figure label="Trips measured" value={String(report.trips_measured)} />
        <Figure label="Passengers carried" value={String(report.passengers_carried)} />
        <Figure label="Seats offered" value={String(report.seats_offered)} />
        <Figure label="Utilisation" value={percent(report.utilisation_percent)} />
      </div>

      <Panel title="By route" className="mt-lg">
        {report.by_route.length === 0 ? (
          <EmptyState icon="routes" title="No activity in this range" />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-body">
              <thead>
                <tr className="border-b border-outline text-left text-label text-on-surface-muted uppercase">
                  <th scope="col" className="h-[var(--size-row-header)] px-lg font-medium">Route</th>
                  <th scope="col" className="px-lg font-medium">Trips</th>
                  <th scope="col" className="px-lg font-medium">Passengers</th>
                  <th scope="col" className="px-lg font-medium">Utilisation</th>
                </tr>
              </thead>
              <tbody>
                {report.by_route.map((route) => (
                  <tr key={route.route_name} className="h-[var(--size-row)] border-b border-outline last:border-0">
                    <td className="px-lg">{route.route_name}</td>
                    <td className="px-lg font-mono">{route.trips}</td>
                    <td className="px-lg font-mono">{route.passengers}</td>
                    <td className="px-lg font-mono">{percent(route.utilisation_percent)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <DownloadTable
          filename={`ctms-occupancy-${report.window.from.slice(0, 10)}.csv`}
          headers={['route', 'trips', 'passengers', 'utilisation_percent']}
          rows={report.by_route.map((route) => [route.route_name, route.trips, route.passengers, route.utilisation_percent])}
          caption={`${report.by_route.length} routes — the whole table, which this endpoint returns unpaginated.`}
        />
      </Panel>
    </>
  )
}

function Fleet({ report }: { report: FleetReport }) {
  return (
    <>
      <p className="mb-lg text-label text-on-surface-muted">As at {new Date(report.generated_at).toLocaleString()}</p>

      <div className="grid gap-lg sm:grid-cols-2 lg:grid-cols-4">
        <Figure label="Buses" value={String(report.buses.total)} />
        <Figure
          label="Grounded by maintenance"
          value={String(report.grounded_by_maintenance)}
          tone={report.grounded_by_maintenance > 0 ? 'caution' : 'positive'}
        />
        <Figure label="Open tickets" value={String(report.open_tickets.total)} />
        <Figure
          label="Overdue for service"
          value={String(report.overdue_maintenance_buses)}
          tone={report.overdue_maintenance_buses > 0 ? 'critical' : 'positive'}
        />
      </div>

      <Breakdown
        title="Buses by status"
        entries={report.buses.by_status}
        filename="ctms-fleet-by-status.csv"
        noun="status"
      />
      <Breakdown
        title="Open tickets by priority"
        entries={report.open_tickets.by_priority}
        filename="ctms-fleet-open-tickets.csv"
        noun="priority"
      />
    </>
  )
}

function Incidents({ report }: { report: IncidentsReport }) {
  return (
    <>
      <WindowNote window={report.window} />
      <div className="grid gap-lg sm:grid-cols-2 lg:grid-cols-4">
        <Figure label="Incidents" value={String(report.total)} />
        <Figure label="Unacknowledged" value={String(report.unacknowledged)} tone={report.unacknowledged > 0 ? 'caution' : 'positive'} />
        <Figure label="Escalated" value={String(report.escalated)} tone={report.escalated > 0 ? 'critical' : 'neutral'} />
        <Figure label="False alarms" value={String(report.cancelled_false_alarms)} />
      </div>

      <Panel title="How fast they were answered" className="mt-lg">
        <FieldGrid columns={3}>
          <Field label="Median acknowledgement">{duration(report.median_acknowledgement_seconds)}</Field>
          <Field label="Life-safety median">{duration(report.life_safety_median_acknowledgement_seconds)}</Field>
          <Field label="Life-safety worst">{duration(report.life_safety_worst_acknowledgement_seconds)}</Field>
        </FieldGrid>
      </Panel>

      <Breakdown title="By class" entries={report.by_class} filename="ctms-incidents-by-class.csv" noun="class" />
      <Breakdown title="By type" entries={report.by_type} filename="ctms-incidents-by-type.csv" noun="type" />
    </>
  )
}

function Attendance({ report }: { report: AttendanceReport }) {
  return (
    <>
      <WindowNote window={report.window} />
      <div className="grid gap-lg sm:grid-cols-2 lg:grid-cols-4">
        <Figure label="Discrepancies" value={String(report.discrepancies)} />
        <Figure label="Unsettled" value={String(report.open)} tone={report.open > 0 ? 'caution' : 'positive'} />
        <Figure label="Settled" value={String(report.reviewed)} />
        <Figure label="Largest difference" value={String(report.largest_difference)} />
      </div>

      <Panel title="Which way they went" className="mt-lg">
        <FieldGrid columns={2}>
          <Field label="Driver counted fewer than boarded">{report.under_accounted}</Field>
          <Field label="Driver counted more than boarded">{report.over_accounted}</Field>
        </FieldGrid>
        <DownloadTable
          filename={`ctms-attendance-${report.window.from.slice(0, 10)}.csv`}
          headers={['measure', 'value']}
          rows={[
            ['Discrepancies', report.discrepancies],
            ['Unsettled', report.open],
            ['Settled', report.reviewed],
            ['Under-accounted', report.under_accounted],
            ['Over-accounted', report.over_accounted],
            ['Largest difference', report.largest_difference],
          ]}
          caption="The figures above, for this window only."
        />
      </Panel>
    </>
  )
}

function Maintenance({ report }: { report: MaintenanceReport }) {
  return (
    <>
      <WindowNote window={report.window} />
      <div className="grid gap-lg sm:grid-cols-2 lg:grid-cols-4">
        <Figure label="Opened" value={String(report.opened)} />
        <Figure label="Completed" value={String(report.completed)} tone="positive" />
        <Figure label="Still open" value={String(report.still_open)} tone={report.still_open > 0 ? 'caution' : 'positive'} />
        <Figure label="Total cost" value={report.total_cost.toLocaleString()} />
      </div>

      <Panel title="Turnaround" className="mt-lg">
        <FieldGrid columns={2}>
          <Field label="Median turnaround">
            {report.median_turnaround_hours === null ? 'No data' : `${report.median_turnaround_hours} h`}
          </Field>
          <Field label="Total cost">{report.total_cost.toLocaleString()}</Field>
        </FieldGrid>
      </Panel>

      <Breakdown
        title="By priority"
        entries={report.by_priority}
        filename="ctms-maintenance-by-priority.csv"
        noun="priority"
      />
    </>
  )
}
