import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { StopStateChip, TripStatusChip } from '@/components/StatusChip'
import { Icon } from '@/icons/Icon'
import { useSession } from '@/auth/SessionProvider'
import { Can } from '@/auth/Can'
import { ActionButton, ConfirmDialog, OperationResult, useOperation } from '@/components/operations'
import { StopManifestPanel } from '@/features/attendance/StopManifestPanel'
import { cancelTrip, reassignTrip } from '@/features/attendance/api'
import { fetchBuses } from '@/features/fleet/api'
import type { ApiFailure } from '@/api/failure'
import {
  CORRECTABLE_FIELDS,
  clock,
  correctTrip,
  correctedByName,
  driverName,
  fetchRouteStops,
  fetchTrip,
  fetchTripCorrections,
  fetchTripLive,
  occupancy,
  tripKeys,
  type Trip,
  type TripCorrection,
} from './api'

/**
 * A4 Trip details.
 *
 * The stop section is the part that had to be verified before it was designed.
 * `GET /trips/{id}/live` answers 200 for every status, and its `stops` come
 * from rows created when the trip *started* — so a completed trip keeps its
 * history, and a scheduled trip genuinely has none. For that last case the
 * screen shows the **planned** route from `GET /routes/{id}/stops`, labelled as
 * planned, rather than an empty timeline pretending history exists.
 */
export function TripDetailScreen() {
  const { id = '' } = useParams()
  const { can } = useSession()

  const trip = useQuery({ queryKey: tripKeys.detail(id), queryFn: () => fetchTrip(id), enabled: Boolean(id) })
  const live = useQuery({ queryKey: tripKeys.live(id), queryFn: () => fetchTripLive(id), enabled: Boolean(id) })
  const corrections = useQuery({
    queryKey: tripKeys.corrections(id),
    queryFn: () => fetchTripCorrections(id),
    enabled: Boolean(id),
  })

  const routeId = trip.data?.route?.id
  const hasHistory = (live.data?.stops.length ?? 0) > 0

  // Only fetched when there is no stop history to show — a scheduled trip.
  const plannedStops = useQuery({
    queryKey: tripKeys.routeStops(routeId ?? ''),
    queryFn: () => fetchRouteStops(routeId!),
    enabled: Boolean(routeId) && live.isSuccess && !hasHistory,
  })

  if (trip.isPending) {
    return (
      <>
        <PageHeader title="Trip" />
        <div className="h-64 animate-pulse rounded-md bg-surface-sunken" />
      </>
    )
  }

  if (trip.isError) {
    const failure = trip.error as ApiFailure

    return (
      <>
        <PageHeader title="Trip" />
        <div className="rounded-md border border-outline bg-surface p-xxl text-center">
          <Icon name="warning" size="lg" className="text-caution" />
          <p className="mt-md text-title-md">{failure?.displayMessage ?? 'This trip could not be loaded.'}</p>
          <Link to="/trips" className="mt-lg inline-block text-body text-primary">
            ← Back to trips
          </Link>
        </div>
      </>
    )
  }

  const value = trip.data!

  return (
    <>
      <PageHeader
        title={value.route?.route_name ?? 'Trip'}
        subtitle={`${value.trip_date} · ${value.route?.route_code ?? ''}`}
        breadcrumb={
          <Link to="/trips" className="text-primary">
            ← Trips
          </Link>
        }
        actions={<TripStatusChip status={value.status} />}
      />

      <TripOperations trip={value} />

      <div className="grid grid-cols-1 gap-lg xl:grid-cols-[1.1fr_1fr]">
        <Summary trip={value} live={live.data ?? null} />

        <section className="rounded-md border border-outline bg-surface">
          <h2 className="border-b border-outline px-lg py-md text-title-md font-semibold">Stops</h2>

          {live.isPending && <div className="m-lg h-40 animate-pulse rounded-sm bg-surface-sunken" />}

          {live.isError && (
            <p className="p-lg text-body text-on-surface-muted">Stop information could not be loaded.</p>
          )}

          {live.isSuccess && hasHistory && (
            <ol className="p-lg">
              {live.data.stops.map((stop) => (
                <li
                  key={stop.stop_id}
                  className="flex items-center gap-md border-b border-outline py-sm last:border-0"
                >
                  <span className="w-6 shrink-0 font-mono text-label text-on-surface-muted">
                    {stop.sequence_number}
                  </span>
                  <span className="min-w-0 flex-1 truncate text-body">{stop.stop_name}</span>
                  <span className="font-mono text-label text-on-surface-muted">
                    {stop.arrived_at ? new Date(stop.arrived_at).toLocaleTimeString(undefined, {
                      hour: '2-digit',
                      minute: '2-digit',
                    }) : '—'}
                  </span>
                  <StopStateChip state={stop.state} />
                  {/* The named roster is OPERATIONS or the assigned driver —
                      `TripPolicy::operate`, not `view`. Read-only oversight
                      sees the counts, not who was on the bus. */}
                  <Can capability="manifest.read">
                    <StopManifestPanel tripId={id} stopId={stop.stop_id} stopName={stop.stop_name} />
                  </Can>
                </li>
              ))}
            </ol>
          )}

          {live.isSuccess && !hasHistory && (
            <PlannedStops
              status={value.status}
              pending={plannedStops.isPending}
              stops={plannedStops.data?.rows ?? []}
            />
          )}
        </section>
      </div>

      <Corrections
        tripId={id}
        rows={corrections.data?.rows ?? []}
        pending={corrections.isPending}
        canCorrect={can('trip.correct')}
      />
    </>
  )
}

/**
 * What can still be done to a trip.
 *
 * Both are OPERATIONS — BR-258 puts amending the record of what a driver did
 * out of reach of read-only oversight, and cancelling or reassigning a service
 * changes the timetable people are standing at stops for.
 */
function TripOperations({ trip }: { trip: Trip }) {
  const invalidate = [tripKeys.detail(trip.id), ['trips', 'list'], ['live']]
  const [dialog, setDialog] = useState<'cancel' | 'reassign' | null>(null)
  const [busId, setBusId] = useState('')

  const cancel = useOperation<string>({ run: (reason) => cancelTrip(trip.id, reason), invalidate })
  const reassign = useOperation<string>({
    run: (reason) => reassignTrip(trip.id, { bus_id: busId || undefined, reason }),
    invalidate,
  })

  const buses = useQuery({
    queryKey: ['fleet', 'picker'],
    queryFn: () => fetchBuses({ page: 1, per_page: 100 }),
    enabled: dialog === 'reassign',
  })

  const settled = trip.status === 'COMPLETED' || trip.status === 'CANCELLED'

  const closeDialog = () => {
    setDialog(null)
    cancel.reset()
    reassign.reset()
  }

  return (
    <div className="mb-lg flex flex-wrap gap-sm">
      <Can capability="trip.reassign">
        <ActionButton
          label="Reassign"
          icon="swap"
          disabled={settled}
          title={settled ? `A trip that is ${trip.status.toLowerCase()} cannot be reassigned.` : undefined}
          onClick={() => setDialog('reassign')}
        />
      </Can>

      <Can capability="trip.cancel">
        <ActionButton
          label="Cancel trip"
          tone="destructive"
          icon="close"
          disabled={settled}
          title={settled ? `A trip that is ${trip.status.toLowerCase()} cannot be cancelled.` : undefined}
          onClick={() => setDialog('cancel')}
        />
      </Can>

      <ConfirmDialog
        open={dialog === 'cancel'}
        title="Cancel this trip?"
        body="Everybody expecting this service is affected. Say why — the reason is kept with the trip."
        confirmLabel="Cancel trip"
        tone="destructive"
        reason={{ label: 'Reason', field: 'reason', minLength: 10, hint: 'At least 10 characters.' }}
        operation={cancel}
        onClose={closeDialog}
        onConfirm={(reason) => void cancel.run(reason).then((ok) => ok && closeDialog())}
      />

      {dialog === 'reassign' && (
        <div className="fixed inset-0 z-50 grid place-items-center bg-black/40 p-lg">
          <div
            role="dialog"
            aria-modal="true"
            aria-label="Reassign this trip"
            className="w-full max-w-lg rounded-md border border-outline bg-surface p-xl"
          >
            <h2 className="text-title-lg font-semibold">Reassign this trip</h2>
            <p className="mt-md text-body text-on-surface-muted">
              Put a different bus on this service. Leaving the bus unchanged reassigns nothing.
            </p>

            <label className="mt-lg block">
              <span className="text-label font-medium text-on-surface-muted uppercase">Bus</span>
              <select
                value={busId}
                onChange={(event) => setBusId(event.target.value)}
                className="mt-xs h-[var(--size-control)] w-full rounded-sm border border-outline bg-surface px-md text-body"
              >
                <option value="">Keep {trip.bus?.registration_number ?? 'the current bus'}</option>
                {buses.data?.rows.map((bus) => (
                  <option key={bus.id} value={bus.id}>
                    {bus.registration_number} · {bus.status.toLowerCase()}
                  </option>
                ))}
              </select>
              {reassign.fieldError('bus_id') && (
                <span className="mt-xs block text-label text-critical">{reassign.fieldError('bus_id')}</span>
              )}
            </label>

            <ReassignReason operation={reassign} onClose={closeDialog} />
          </div>
        </div>
      )}
    </div>
  )
}

function ReassignReason({
  operation,
  onClose,
}: {
  operation: ReturnType<typeof useOperation<string>>
  onClose: () => void
}) {
  const [reason, setReason] = useState('')

  return (
    <>
      <label className="mt-lg block">
        <span className="text-label font-medium text-on-surface-muted uppercase">Reason</span>
        <textarea
          value={reason}
          onChange={(event) => setReason(event.target.value)}
          rows={3}
          className="mt-xs w-full rounded-sm border border-outline bg-surface p-md text-body"
        />
        {operation.fieldError('reason') && (
          <span className="mt-xs block text-label text-critical">{operation.fieldError('reason')}</span>
        )}
      </label>

      <div className="mt-lg">
        <OperationResult operation={operation} />
      </div>

      <div className="mt-lg flex justify-end gap-sm">
        <ActionButton label="Cancel" onClick={onClose} />
        <ActionButton
          label="Reassign"
          tone="primary"
          busy={operation.isPending}
          disabled={reason.trim().length < 5}
          title={reason.trim().length < 5 ? 'Give a reason of at least 5 characters.' : undefined}
          onClick={() => void operation.run(reason.trim()).then((ok) => ok && onClose())}
        />
      </div>
    </>
  )
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex justify-between gap-lg border-b border-outline py-sm last:border-0">
      <span className="text-body text-on-surface-muted">{label}</span>
      <span className="text-right text-body font-semibold">{children}</span>
    </div>
  )
}

function Summary({ trip, live }: { trip: Trip; live: { position: { is_stale: boolean; recorded_at: string } | null; delay_minutes: number | null } | null }) {
  return (
    <section className="rounded-md border border-outline bg-surface">
      <h2 className="border-b border-outline px-lg py-md text-title-md font-semibold">Summary</h2>

      <div className="px-lg py-sm">
        <Field label="Bus">
          <span className="font-mono">{trip.bus?.registration_number ?? '—'}</span>
          {trip.bus?.model && <span className="ml-sm font-normal text-on-surface-muted">{trip.bus.model}</span>}
        </Field>
        <Field label="Driver">{driverName(trip)}</Field>
        <Field label="Scheduled departure">
          <span className="font-mono">{clock(trip.scheduled_departure_time)}</span>
        </Field>
        <Field label="Actual departure">
          <span className="font-mono">{clock(trip.actual_departure_time)}</span>
        </Field>
        <Field label="Scheduled arrival">
          <span className="font-mono">{clock(trip.scheduled_arrival_time)}</span>
        </Field>
        <Field label="Actual arrival">
          <span className="font-mono">{clock(trip.actual_arrival_time)}</span>
        </Field>
        <Field label="Occupancy">
          <span className="font-mono">{occupancy(trip)}</span>
        </Field>
        {trip.route?.total_distance_km != null && (
          <Field label="Route distance">
            <span className="font-mono">{trip.route.total_distance_km} km</span>
          </Field>
        )}

        {/* Position freshness is the server's judgement, never recomputed. */}
        {live?.position && (
          <Field label="Last position">
            {live.position.is_stale ? (
              <span className="text-caution">Not updating</span>
            ) : (
              <span className="text-positive">Live</span>
            )}
          </Field>
        )}

        {trip.auto_closed && (
          <Field label="Closed">
            <span className="text-caution">Automatically, not by the driver</span>
          </Field>
        )}
        {trip.cancellation_reason && (
          <Field label="Cancelled because">
            <span className="font-normal">{trip.cancellation_reason}</span>
          </Field>
        )}
      </div>
    </section>
  )
}

function PlannedStops({
  status,
  pending,
  stops,
}: {
  status: string
  pending: boolean
  stops: Array<{ id: string; stop_name: string; sequence_number: number; estimated_arrival_minutes: number | null }>
}) {
  if (pending) return <div className="m-lg h-40 animate-pulse rounded-sm bg-surface-sunken" />

  return (
    <div className="p-lg">
      <p className="mb-md flex items-start gap-sm text-body text-on-surface-muted">
        <Icon name="warning" size="sm" className="mt-xs text-caution" />
        <span>
          {status === 'SCHEDULED'
            ? 'This trip has not started, so there is no stop history yet. The planned route is below.'
            : 'No stop record exists for this trip. The planned route is below.'}
        </span>
      </p>

      <ol>
        {stops.map((stop) => (
          <li key={stop.id} className="flex items-center gap-md border-b border-outline py-sm last:border-0">
            <span className="w-6 shrink-0 font-mono text-label text-on-surface-muted">{stop.sequence_number}</span>
            <span className="min-w-0 flex-1 truncate text-body">{stop.stop_name}</span>
            {stop.estimated_arrival_minutes != null && (
              <span className="font-mono text-label text-on-surface-muted">
                +{stop.estimated_arrival_minutes} min
              </span>
            )}
          </li>
        ))}
      </ol>
    </div>
  )
}

/**
 * BR-258. Correcting a closed trip's record is an OPERATIONS decision — the
 * record is the evidence of what a driver did.
 *
 * `can('trip.correct')` decides whether the control is *offered*. The server
 * decides what happens; a 403 arriving anyway is rendered, never swallowed.
 */
function Corrections({
  tripId,
  rows,
  pending,
  canCorrect,
}: {
  tripId: string
  rows: TripCorrection[]
  pending: boolean
  canCorrect: boolean
}) {
  const [open, setOpen] = useState(false)

  return (
    <section className="mt-lg rounded-md border border-outline bg-surface">
      <div className="flex items-center gap-lg border-b border-outline px-lg py-md">
        <h2 className="text-title-md font-semibold">Corrections</h2>
        {canCorrect && (
          <button
            type="button"
            onClick={() => setOpen(true)}
            className="ml-auto h-[var(--size-control)] rounded-sm border border-outline px-lg text-body"
          >
            Correct this trip
          </button>
        )}
      </div>

      {pending && <div className="m-lg h-16 animate-pulse rounded-sm bg-surface-sunken" />}

      {!pending && rows.length === 0 && (
        <p className="p-lg text-body text-on-surface-muted">This trip&rsquo;s record has never been corrected.</p>
      )}

      {rows.length > 0 && (
        <ul className="p-lg">
          {rows.map((row) => (
            <li key={row.id} className="border-b border-outline py-sm last:border-0">
              <div className="flex flex-wrap items-baseline gap-sm">
                <span className="font-semibold">{row.field}</span>
                <span className="font-mono text-label text-on-surface-muted">
                  {row.original_value ?? '—'} → {row.corrected_value ?? '—'}
                </span>
                <span className="ml-auto text-label text-on-surface-muted">
                  {correctedByName(row)} · {new Date(row.created_at).toLocaleString()}
                </span>
              </div>
              <p className="mt-xs text-body text-on-surface-muted">{row.reason}</p>
            </li>
          ))}
        </ul>
      )}

      {open && <CorrectionDialog tripId={tripId} onClose={() => setOpen(false)} />}
    </section>
  )
}

function CorrectionDialog({ tripId, onClose }: { tripId: string; onClose: () => void }) {
  const queryClient = useQueryClient()
  const [field, setField] = useState<string>(CORRECTABLE_FIELDS[0].value)
  const [value, setValue] = useState('')
  const [reason, setReason] = useState('')

  const mutation = useMutation({
    mutationFn: () => correctTrip(tripId, { field, value, reason }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['trips'] })
      onClose()
    },
  })

  const failure = mutation.error as ApiFailure | null

  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-black/40 p-lg" role="dialog" aria-modal="true">
      <form
        onSubmit={(event) => {
          event.preventDefault()
          mutation.mutate()
        }}
        className="w-full max-w-[480px] rounded-md border border-outline bg-surface p-xl"
      >
        <h3 className="text-title-md font-semibold">Correct this trip</h3>
        <p className="mt-xs mb-lg text-body text-on-surface-muted">
          The original value is kept. A correction is recorded against your name and cannot be removed.
        </p>

        {failure && (
          <div
            role="alert"
            className="mb-lg rounded-sm border border-critical/40 bg-critical/10 p-md text-body text-critical"
          >
            {/* 409 and 422 carry the server's own words. 403 does not. */}
            {failure.displayMessage}
          </div>
        )}

        <label className="mb-md block">
          <span className="mb-xs block text-label font-medium">Field</span>
          <select
            value={field}
            onChange={(event) => setField(event.target.value)}
            className="h-[var(--size-control)] w-full rounded-sm border border-outline bg-surface px-md text-body"
          >
            {CORRECTABLE_FIELDS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </label>

        <label className="mb-md block">
          <span className="mb-xs block text-label font-medium">Corrected value</span>
          <input
            value={value}
            onChange={(event) => setValue(event.target.value)}
            className="h-[var(--size-control)] w-full rounded-sm border border-outline bg-surface px-md text-body"
          />
        </label>

        <label className="mb-lg block">
          <span className="mb-xs block text-label font-medium">Reason</span>
          <textarea
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            rows={3}
            minLength={5}
            required
            className="w-full rounded-sm border border-outline bg-surface p-md text-body"
          />
          <span className="mt-xs block text-label text-on-surface-muted">
            At least five characters. A correction without a stated reason is indistinguishable from tampering when
            somebody reads it back in six months.
          </span>
        </label>

        <div className="flex justify-end gap-sm">
          <button
            type="button"
            onClick={onClose}
            className="h-[var(--size-control)] rounded-sm border border-outline px-lg text-body"
          >
            Cancel
          </button>
          <button
            type="submit"
            disabled={mutation.isPending}
            className="h-[var(--size-control)] rounded-sm bg-primary px-lg text-body font-semibold text-on-primary disabled:opacity-60"
          >
            {mutation.isPending ? 'Recording…' : 'Record correction'}
          </button>
        </div>
      </form>
    </div>
  )
}
