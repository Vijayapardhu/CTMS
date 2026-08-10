import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { StatusChip, type StatusTone } from '@/components/StatusChip'
import { EmptyState, LoadFailed, LoadingRows, Pager, Panel, RangeLabel, RefreshButton, StaleBanner } from '@/components/Panel'
import { ActionButton, OperationResult, useOperation } from '@/components/operations'
import { Can } from '@/auth/Can'
import { fetchRoutes, fetchRouteStops } from '@/features/routes/api'
import {
  assignStudentTransport,
  fetchStudents,
  humanise,
  peopleKeys,
  personName,
  setStudentStatus,
  shortDate,
  type Student,
  type StudentFilters,
  type StudentStatus,
} from './api'

const STATUSES: StudentStatus[] = ['ACTIVE', 'INACTIVE', 'SUSPENDED']

const TONE: Record<StudentStatus, StatusTone> = {
  ACTIVE: 'positive',
  INACTIVE: 'neutral',
  SUSPENDED: 'critical',
}

/**
 * A12 Students.
 *
 * Deliberately austere. This is a transport roster, not a student record
 * system: registration number, department, which route they are on and whether
 * their ticket is valid. Address, date of birth and the rest exist on the user
 * but have no business being assembled on a transport screen.
 */
export function StudentsScreen() {
  const [params, setParams] = useSearchParams()
  const queryClient = useQueryClient()

  const filters: StudentFilters = {
    status: params.get('status') ?? '',
    route_id: params.get('route_id') ?? '',
    unassigned: params.get('unassigned') === '1',
    search: params.get('search') ?? '',
    page: Number(params.get('page') ?? 1),
  }

  const students = useQuery({ queryKey: peopleKeys.students(filters), queryFn: () => fetchStudents(filters) })
  const routes = useQuery({ queryKey: ['routes', 'picker'], queryFn: () => fetchRoutes({ per_page: 100 }) })

  const update = (next: Record<string, string | number | undefined>) => {
    const merged = new URLSearchParams(params)
    for (const [key, value] of Object.entries(next)) {
      if (value === undefined || value === '') merged.delete(key)
      else merged.set(key, String(value))
    }
    if (!('page' in next)) merged.delete('page')
    setParams(merged, { replace: true })
  }

  return (
    <>
      <PageHeader
        title="Students"
        subtitle="Who travels, on which route, and whether their ticket is valid."
        actions={
          <RefreshButton
            onClick={() => void queryClient.invalidateQueries({ queryKey: ['students'] })}
            busy={students.isFetching}
          />
        }
      />

      <div className="mb-lg flex flex-wrap items-end gap-md rounded-md border border-outline bg-surface p-md">
        <label className="flex flex-col gap-xs">
          <span className="text-label font-medium text-on-surface-muted uppercase">Status</span>
          <select
            value={filters.status}
            onChange={(event) => update({ status: event.target.value })}
            className="h-[var(--size-control)] rounded-sm border border-outline bg-surface px-md text-body"
          >
            <option value="">Every status</option>
            {STATUSES.map((value) => (
              <option key={value} value={value}>
                {humanise(value)}
              </option>
            ))}
          </select>
        </label>

        <label className="flex flex-col gap-xs">
          <span className="text-label font-medium text-on-surface-muted uppercase">Route</span>
          <select
            value={filters.route_id}
            onChange={(event) => update({ route_id: event.target.value, unassigned: undefined })}
            className="h-[var(--size-control)] rounded-sm border border-outline bg-surface px-md text-body"
          >
            <option value="">Every route</option>
            {routes.data?.rows.map((route) => (
              <option key={route.id} value={route.id}>
                {route.route_name}
              </option>
            ))}
          </select>
        </label>

        <label className="flex flex-col gap-xs">
          <span className="text-label font-medium text-on-surface-muted uppercase">Search</span>
          <input
            type="search"
            defaultValue={filters.search}
            placeholder="Name or registration"
            onChange={(event) => update({ search: event.target.value })}
            className="h-[var(--size-control)] w-56 rounded-sm border border-outline bg-surface px-md text-body"
          />
        </label>

        <button
          type="button"
          onClick={() => update({ unassigned: filters.unassigned ? undefined : '1', route_id: undefined })}
          className="h-[var(--size-control)] rounded-sm border border-outline px-md text-body"
        >
          {filters.unassigned ? 'Everybody' : 'Only those with no route'}
        </button>

        <span className="ml-auto">
          <RangeLabel pagination={students.data?.pagination} noun="students" />
        </span>
      </div>

      <Panel>
        {students.isError && students.data && (
          <StaleBanner error={students.error} onRetry={() => void students.refetch()} />
        )}
        {students.isPending && <LoadingRows />}
        {students.isError && !students.data && (
          <LoadFailed what="students" error={students.error} onRetry={() => void students.refetch()} />
        )}
        {students.data && students.data.rows.length === 0 && (
          <EmptyState icon="students" title="No students match these filters" />
        )}

        {students.data && students.data.rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-body">
              <thead>
                <tr className="border-b border-outline text-left text-label text-on-surface-muted uppercase">
                  <th scope="col" className="h-[var(--size-row-header)] px-lg font-medium">Name</th>
                  <th scope="col" className="px-lg font-medium">Registration</th>
                  <th scope="col" className="px-lg font-medium">Department</th>
                  <th scope="col" className="px-lg font-medium">Route</th>
                  <th scope="col" className="px-lg font-medium">Ticket</th>
                  <th scope="col" className="px-lg font-medium">Status</th>
                  <th scope="col" className="px-lg font-medium" />
                </tr>
              </thead>
              <tbody>
                {students.data.rows.map((student) => (
                  <StudentRow key={student.id} student={student} />
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Panel>

      <Pager pagination={students.data?.pagination} onPage={(page) => update({ page })} />
    </>
  )
}

function StudentRow({ student }: { student: Student }) {
  const invalidate = [['students']]
  const [assigning, setAssigning] = useState(false)

  const status = useOperation<StudentStatus>({
    run: (next) => setStudentStatus(student.id, next),
    invalidate,
  })
  const assign = useOperation<{ route_id: string; pickup_stop_id: string }>({
    run: (body) => assignStudentTransport(student.id, body),
    invalidate,
  })

  return (
    <tr className="border-b border-outline align-top last:border-0">
      <td className="px-lg py-md">{personName(student.user)}</td>
      <td className="px-lg py-md font-mono">{student.registration_number ?? '—'}</td>
      <td className="px-lg py-md">
        {student.department ?? '—'}
        {student.year_of_study && (
          <span className="ml-sm text-label text-on-surface-muted">year {student.year_of_study}</span>
        )}
      </td>
      <td className="px-lg py-md">
        {student.route ? (
          <>
            {student.route.route_name}
            {student.pickup_stop && (
              <span className="mt-xs block text-label text-on-surface-muted">
                from {student.pickup_stop.stop_name}
              </span>
            )}
          </>
        ) : (
          <span className="text-on-surface-muted">Not assigned</span>
        )}
      </td>
      <td className="px-lg py-md">
        {student.has_valid_ticket === null ? (
          '—'
        ) : student.has_valid_ticket ? (
          <>
            <StatusChip label="Valid" tone="positive" icon="success" />
            {student.ticket_expiry_date && (
              <span className="mt-xs block text-label text-on-surface-muted">
                to {shortDate(student.ticket_expiry_date)}
              </span>
            )}
          </>
        ) : (
          <StatusChip label="Not valid" tone="caution" icon="warning" />
        )}
      </td>
      <td className="px-lg py-md">
        <StatusChip label={humanise(student.status)} tone={TONE[student.status] ?? 'neutral'} />
        {(status.failure || status.success || assign.failure || assign.success) && (
          <div className="mt-xs max-w-sm">
            <OperationResult operation={status.failure || status.success ? status : assign} />
          </div>
        )}
      </td>
      <td className="px-lg py-md text-right">
        <div className="flex flex-wrap justify-end gap-sm">
          <Can capability="student.setStatus">
            <label className="flex items-center gap-sm text-label">
              <span className="sr-only">Set status for {personName(student.user)}</span>
              <select
                value=""
                disabled={status.isPending}
                onChange={(event) => {
                  if (event.target.value) void status.run(event.target.value as StudentStatus)
                }}
                className="h-[var(--size-control)] rounded-sm border border-outline bg-surface px-md text-body"
              >
                <option value="">Set status…</option>
                {STATUSES.filter((value) => value !== student.status).map((value) => (
                  <option key={value} value={value}>
                    {humanise(value)}
                  </option>
                ))}
              </select>
            </label>
          </Can>

          <Can capability="student.assignTransport">
            <ActionButton
              label={student.route ? 'Change route' : 'Assign transport'}
              icon="routes"
              onClick={() => setAssigning(true)}
            />
          </Can>
        </div>

        {assigning && (
          <AssignTransportDialog
            studentName={personName(student.user)}
            operation={assign}
            onClose={() => {
              setAssigning(false)
              assign.reset()
            }}
          />
        )}
      </td>
    </tr>
  )
}

/**
 * Assigning transport takes a route **and** a stop on that route, so the stop
 * list is fetched for whichever route is chosen rather than offered as a flat
 * list of every stop in the college.
 */
function AssignTransportDialog({
  studentName,
  operation,
  onClose,
}: {
  studentName: string
  operation: ReturnType<typeof useOperation<{ route_id: string; pickup_stop_id: string }>>
  onClose: () => void
}) {
  const [routeId, setRouteId] = useState('')
  const [stopId, setStopId] = useState('')

  const routes = useQuery({ queryKey: ['routes', 'picker'], queryFn: () => fetchRoutes({ per_page: 100 }) })
  const stops = useQuery({
    queryKey: ['routes', routeId, 'stops'],
    queryFn: () => fetchRouteStops(routeId),
    enabled: Boolean(routeId),
  })

  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-black/40 p-lg text-left">
      <div
        role="dialog"
        aria-modal="true"
        aria-label={`Assign transport for ${studentName}`}
        className="w-full max-w-md rounded-md border border-outline bg-surface p-xl"
      >
        <h2 className="text-title-lg font-semibold">Assign transport for {studentName}</h2>

        <label className="mt-lg block">
          <span className="text-label font-medium text-on-surface-muted uppercase">Route</span>
          <select
            value={routeId}
            onChange={(event) => {
              setRouteId(event.target.value)
              setStopId('')
            }}
            className="mt-xs h-[var(--size-control)] w-full rounded-sm border border-outline bg-surface px-md text-body"
          >
            <option value="">Choose a route</option>
            {routes.data?.rows.map((route) => (
              <option key={route.id} value={route.id}>
                {route.route_name}
              </option>
            ))}
          </select>
        </label>

        <label className="mt-lg block">
          <span className="text-label font-medium text-on-surface-muted uppercase">Pick-up stop</span>
          <select
            value={stopId}
            onChange={(event) => setStopId(event.target.value)}
            disabled={!routeId}
            className="mt-xs h-[var(--size-control)] w-full rounded-sm border border-outline bg-surface px-md text-body disabled:opacity-50"
          >
            <option value="">{routeId ? 'Choose a stop' : 'Choose a route first'}</option>
            {stops.data?.rows.map((stop) => (
              <option key={stop.id} value={stop.id}>
                {stop.sequence_number}. {stop.stop_name}
              </option>
            ))}
          </select>
          {operation.fieldError('pickup_stop_id') && (
            <span className="mt-xs block text-label text-critical">{operation.fieldError('pickup_stop_id')}</span>
          )}
        </label>

        <div className="mt-lg">
          <OperationResult operation={operation} />
        </div>

        <div className="mt-lg flex justify-end gap-sm">
          <ActionButton label="Cancel" onClick={onClose} />
          <ActionButton
            label="Assign"
            tone="primary"
            busy={operation.isPending}
            disabled={!routeId || !stopId}
            onClick={() =>
              void operation.run({ route_id: routeId, pickup_stop_id: stopId }).then((ok) => ok && onClose())
            }
          />
        </div>
      </div>
    </div>
  )
}
