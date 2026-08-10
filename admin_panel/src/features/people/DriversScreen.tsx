import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { StatusChip, type StatusTone } from '@/components/StatusChip'
import { EmptyState, LoadFailed, LoadingRows, Pager, Panel, RangeLabel, RefreshButton, StaleBanner } from '@/components/Panel'
import { ActionButton, OperationResult, useOperation } from '@/components/operations'
import { Can } from '@/auth/Can'
import { fetchBuses } from '@/features/fleet/api'
import {
  assignDriverBus,
  daysUntil,
  fetchDrivers,
  humanise,
  peopleKeys,
  personName,
  setDriverStatus,
  shortDate,
  type Driver,
  type DriverFilters,
  type DriverStatus,
} from './api'

const STATUSES: DriverStatus[] = ['AVAILABLE', 'ON_TRIP', 'LEAVE', 'OFF_DUTY']

const TONE: Record<DriverStatus, StatusTone> = {
  AVAILABLE: 'positive',
  ON_TRIP: 'info',
  LEAVE: 'caution',
  OFF_DUTY: 'neutral',
}

/**
 * A7 Drivers.
 *
 * The licence expiry is shown with days remaining because that is the column
 * somebody actually scans for — a licence that lapses on Tuesday is a bus that
 * does not go out on Tuesday.
 */
export function DriversScreen() {
  const [params, setParams] = useSearchParams()
  const queryClient = useQueryClient()

  const filters: DriverFilters = {
    status: params.get('status') ?? '',
    assignable: params.get('assignable') === '1',
    search: params.get('search') ?? '',
    page: Number(params.get('page') ?? 1),
  }

  const drivers = useQuery({ queryKey: peopleKeys.drivers(filters), queryFn: () => fetchDrivers(filters) })

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
        title="Drivers"
        subtitle="Who can take a bus out, and whether their licence still says so."
        actions={
          <RefreshButton
            onClick={() => void queryClient.invalidateQueries({ queryKey: ['drivers'] })}
            busy={drivers.isFetching}
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
          <span className="text-label font-medium text-on-surface-muted uppercase">Search</span>
          <input
            type="search"
            defaultValue={filters.search}
            placeholder="Name, email or licence"
            onChange={(event) => update({ search: event.target.value })}
            className="h-[var(--size-control)] w-56 rounded-sm border border-outline bg-surface px-md text-body"
          />
        </label>

        <button
          type="button"
          onClick={() => update({ assignable: filters.assignable ? undefined : '1' })}
          className="h-[var(--size-control)] rounded-sm border border-outline px-md text-body"
        >
          {filters.assignable ? 'Everybody' : 'Only those who can be assigned'}
        </button>

        <span className="ml-auto">
          <RangeLabel pagination={drivers.data?.pagination} noun="drivers" />
        </span>
      </div>

      <Panel>
        {drivers.isError && drivers.data && (
          <StaleBanner error={drivers.error} onRetry={() => void drivers.refetch()} />
        )}
        {drivers.isPending && <LoadingRows />}
        {drivers.isError && !drivers.data && (
          <LoadFailed what="drivers" error={drivers.error} onRetry={() => void drivers.refetch()} />
        )}
        {drivers.data && drivers.data.rows.length === 0 && (
          <EmptyState icon="drivers" title="No drivers match these filters" />
        )}

        {drivers.data && drivers.data.rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-body">
              <thead>
                <tr className="border-b border-outline text-left text-label text-on-surface-muted uppercase">
                  <th scope="col" className="h-[var(--size-row-header)] px-lg font-medium">Name</th>
                  <th scope="col" className="px-lg font-medium">Licence</th>
                  <th scope="col" className="px-lg font-medium">Expires</th>
                  <th scope="col" className="px-lg font-medium">Status</th>
                  <th scope="col" className="px-lg font-medium">Assigned bus</th>
                  <th scope="col" className="px-lg font-medium" />
                </tr>
              </thead>
              <tbody>
                {drivers.data.rows.map((driver) => (
                  <DriverRow key={driver.id} driver={driver} />
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Panel>

      <Pager pagination={drivers.data?.pagination} onPage={(page) => update({ page })} />
    </>
  )
}

function DriverRow({ driver }: { driver: Driver }) {
  const invalidate = [['drivers'], ['fleet']]
  const [assigning, setAssigning] = useState(false)

  const status = useOperation<DriverStatus>({
    run: (next) => setDriverStatus(driver.id, next),
    invalidate,
  })
  const assign = useOperation<string>({ run: (busId) => assignDriverBus(driver.id, busId), invalidate })

  const days = daysUntil(driver.license_expiry_date)

  return (
    <tr className="border-b border-outline align-top last:border-0">
      <td className="px-lg py-md">
        {personName(driver.user)}
        {driver.user?.phone_number && (
          <span className="mt-xs block text-label text-on-surface-muted">{driver.user.phone_number}</span>
        )}
      </td>
      <td className="px-lg py-md font-mono">{driver.license_number ?? '—'}</td>
      <td className="px-lg py-md whitespace-nowrap">
        {shortDate(driver.license_expiry_date)}
        {days !== null && (
          <span className={`ml-sm text-label ${days < 0 ? 'font-semibold text-critical' : days < 30 ? 'text-caution' : 'text-on-surface-muted'}`}>
            {days < 0 ? 'expired' : days === 0 ? 'today' : `in ${days} days`}
          </span>
        )}
      </td>
      <td className="px-lg py-md">
        <StatusChip label={humanise(driver.status)} tone={TONE[driver.status] ?? 'neutral'} />
        {(status.failure || status.success || assign.failure || assign.success) && (
          <div className="mt-xs max-w-sm">
            <OperationResult operation={status.failure || status.success ? status : assign} />
          </div>
        )}
      </td>
      <td className="px-lg py-md">
        {driver.assigned_bus ? (
          <Link to={`/buses/${driver.assigned_bus.id}`} className="font-mono text-primary">
            {driver.assigned_bus.registration_number}
          </Link>
        ) : (
          <span className="text-on-surface-muted">None</span>
        )}
      </td>
      <td className="px-lg py-md text-right">
        <div className="flex flex-wrap justify-end gap-sm">
          {/* `driver.setStatus` is subject-scoped: a driver may set their own
              status from the app. From here it is OPERATIONS. */}
          <Can capability="driver.setStatus">
            <label className="flex items-center gap-sm text-label">
              <span className="sr-only">Set status for {personName(driver.user)}</span>
              <select
                value=""
                disabled={status.isPending}
                onChange={(event) => {
                  if (event.target.value) void status.run(event.target.value as DriverStatus)
                }}
                className="h-[var(--size-control)] rounded-sm border border-outline bg-surface px-md text-body"
              >
                <option value="">Set status…</option>
                {STATUSES.filter((value) => value !== driver.status).map((value) => (
                  <option key={value} value={value}>
                    {humanise(value)}
                  </option>
                ))}
              </select>
            </label>
          </Can>

          <Can capability="driver.assignBus">
            <ActionButton label="Assign a bus" icon="buses" onClick={() => setAssigning(true)} />
          </Can>
        </div>

        {assigning && (
          <AssignBusDialog
            driverName={personName(driver.user)}
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

function AssignBusDialog({
  driverName,
  operation,
  onClose,
}: {
  driverName: string
  operation: ReturnType<typeof useOperation<string>>
  onClose: () => void
}) {
  const [busId, setBusId] = useState('')
  const buses = useQuery({ queryKey: ['fleet', 'picker'], queryFn: () => fetchBuses({ page: 1, per_page: 100 }) })

  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-black/40 p-lg text-left">
      <div
        role="dialog"
        aria-modal="true"
        aria-label={`Assign a bus to ${driverName}`}
        className="w-full max-w-md rounded-md border border-outline bg-surface p-xl"
      >
        <h2 className="text-title-lg font-semibold">Assign a bus to {driverName}</h2>

        <label className="mt-lg block">
          <span className="text-label font-medium text-on-surface-muted uppercase">Bus</span>
          <select
            value={busId}
            onChange={(event) => setBusId(event.target.value)}
            className="mt-xs h-[var(--size-control)] w-full rounded-sm border border-outline bg-surface px-md text-body"
          >
            <option value="">Choose a bus</option>
            {buses.data?.rows.map((bus) => (
              <option key={bus.id} value={bus.id}>
                {bus.registration_number} · {bus.status.toLowerCase()}
              </option>
            ))}
          </select>
          {operation.fieldError('bus_id') && (
            <span className="mt-xs block text-label text-critical">{operation.fieldError('bus_id')}</span>
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
            disabled={!busId}
            onClick={() => void operation.run(busId).then((ok) => ok && onClose())}
          />
        </div>
      </div>
    </div>
  )
}
