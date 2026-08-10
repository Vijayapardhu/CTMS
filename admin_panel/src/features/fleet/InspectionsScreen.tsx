import { useQueries, useQuery } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { StatusChip } from '@/components/StatusChip'
import { EmptyState, LoadFailed, LoadingRows, Panel } from '@/components/Panel'
import { ActionButton } from '@/components/operations'
import { Can } from '@/auth/Can'
import { Icon } from '@/icons/Icon'
import { request } from '@/api/client'
import { fetchBuses, fleetKeys, type Bus } from './api'

/** How many readiness calls this screen is willing to make. */
const MAX_CHECKS = 8

type Readiness = {
  cleared: boolean
  reasons: string[]
  inspection?: { id: string; outcome: string; inspected_at: string } | null
}

const fetchReadiness = async (busId: string): Promise<Readiness> =>
  (await request<Readiness>(`/buses/${busId}/service-readiness`)).data

/**
 * A11 Inspections.
 *
 * **Read the scope before changing this.** There is no fleet-wide inspection
 * endpoint (G2-2). Readiness is one call per bus, so a screen that showed the
 * whole fleet's readiness would be thirty requests to answer one question.
 *
 * This is therefore **today's problem buses**, not an inspection history: the
 * buses that are not AVAILABLE, capped at eight readiness calls, with the
 * header saying exactly that. Full inspection history for one bus lives on A6,
 * where `GET /buses/{id}/inspections` supports it properly.
 */
export function InspectionsScreen() {
  const buses = useQuery({
    queryKey: fleetKeys.list({ page: 1, per_page: 100 }),
    queryFn: () => fetchBuses({ page: 1, per_page: 100 }),
  })

  const offRoad = (buses.data?.rows ?? []).filter((bus) => bus.status !== 'AVAILABLE')
  const checked = offRoad.slice(0, MAX_CHECKS)
  const notChecked = offRoad.length - checked.length

  const readiness = useQueries({
    queries: checked.map((bus) => ({
      queryKey: ['fleet', bus.id, 'readiness'],
      queryFn: () => fetchReadiness(bus.id),
    })),
  })

  return (
    <>
      <PageHeader
        title="Inspections"
        subtitle="Buses that are not available today, and why they are not cleared."
      />

      <p className="mb-lg flex items-start gap-sm rounded-md border border-outline bg-surface p-md text-body">
        <Icon name="inspections" size="sm" className="mt-xs text-on-surface-muted" />
        <span>
          This is today's problem list, not an inspection history. Readiness is one request per bus, so it is
          asked for the buses that are off the road — up to {MAX_CHECKS} of them. A bus's full inspection
          history is on its own page.
        </span>
      </p>

      {buses.isPending && <LoadingRows rows={3} />}

      {buses.isError && (
        <Panel>
          <LoadFailed what="the fleet" error={buses.error} onRetry={() => void buses.refetch()} />
        </Panel>
      )}

      {buses.data && offRoad.length === 0 && (
        <Panel>
          <EmptyState
            icon="success"
            title="Every bus is available"
            hint="Nothing in the fleet is held off the road today."
          />
        </Panel>
      )}

      {checked.length > 0 && (
        <div className="grid gap-lg lg:grid-cols-2">
          {checked.map((bus, index) => (
            <BusReadiness key={bus.id} bus={bus} query={readiness[index]} />
          ))}
        </div>
      )}

      {notChecked > 0 && (
        <p className="mt-lg rounded-md border border-outline bg-surface p-md text-body text-on-surface-muted">
          {notChecked} more {notChecked === 1 ? 'bus is' : 'buses are'} off the road and{' '}
          {notChecked === 1 ? 'was' : 'were'} not checked here — the cap is {MAX_CHECKS} readiness requests.
          Open a bus to see its own readiness.
        </p>
      )}
    </>
  )
}

function BusReadiness({
  bus,
  query,
}: {
  bus: Bus
  query: { data?: Readiness; isPending: boolean; isError: boolean; error?: unknown; refetch: () => unknown }
}) {
  const navigate = useNavigate()

  return (
    <Panel
      title={bus.registration_number}
      action={
        <Link to={`/buses/${bus.id}`} className="text-label text-primary">
          Open
        </Link>
      }
    >
      <div className="px-lg py-md">
        <StatusChip
          label={bus.status.charAt(0) + bus.status.slice(1).toLowerCase()}
          tone={bus.status === 'BREAKDOWN' ? 'critical' : bus.status === 'MAINTENANCE' ? 'caution' : 'neutral'}
        />
      </div>

      {query.isPending && <div className="mx-lg mb-lg h-16 animate-pulse rounded-sm bg-surface-sunken" />}

      {query.isError && (
        <p className="px-lg pb-lg text-body text-on-surface-muted">
          Readiness for this bus could not be loaded.{' '}
          <button type="button" onClick={() => query.refetch()} className="font-semibold text-primary">
            Try again
          </button>
        </p>
      )}

      {query.data && (
        <div className="px-lg pb-lg">
          {query.data.cleared ? (
            <p className="flex items-center gap-sm text-body">
              <Icon name="success" size="sm" className="text-positive" />
              Cleared for service — held off the road for another reason.
            </p>
          ) : (
            <>
              <p className="flex items-center gap-sm text-body font-semibold">
                <Icon name="blocked" size="sm" className="text-critical" />
                Not cleared for service
              </p>
              <ul className="mt-sm">
                {query.data.reasons.map((reason) => (
                  <li key={reason} className="flex items-start gap-sm py-xs text-body">
                    <Icon name="warning" size="xs" className="mt-xs text-caution" />
                    {reason}
                  </li>
                ))}
              </ul>
            </>
          )}

          {/* Raising work from a failure lands on the maintenance screen with
              the bus already chosen — two screens, one intent, no invented
              endpoint that does both. */}
          <Can capability="maintenance.open">
            <div className="mt-md">
              <ActionButton
                label="Open a ticket for this bus"
                icon="maintenance"
                onClick={() => navigate(`/maintenance?bus_id=${bus.id}&compose=1`)}
              />
            </div>
          </Can>
        </div>
      )}
    </Panel>
  )
}
