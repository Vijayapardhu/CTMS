import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { Field, FieldGrid, LoadFailed, LoadingRows, Panel } from '@/components/Panel'
import { ActionButton, ConfirmDialog, OperationResult, useOperation } from '@/components/operations'
import { Can } from '@/auth/Can'
import { Icon } from '@/icons/Icon'
import { request } from '@/api/client'
import { PriorityChip, TicketStatusChip } from './MaintenanceScreen'
import {
  amount,
  assignTicket,
  cancelTicket,
  canTransition,
  changeBusStatus,
  completeTicket,
  fetchTicket,
  humanise,
  isTerminal,
  maintenanceKeys,
  personName,
  scheduleTicket,
  shortDate,
  startTicket,
  type MaintenanceTicket,
} from './api'

/**
 * A maintenance ticket, and the six things that can be done to it.
 *
 * The tiers are the route's own, not a guess: assign, schedule and start are
 * `access:SUPPORT`; complete and cancel are `access:OPERATIONS`, because
 * signing work off is the act that puts a vehicle back on the road (BR-358).
 */
export function MaintenanceDetailScreen() {
  const { id = '' } = useParams()
  const ticket = useQuery({ queryKey: maintenanceKeys.detail(id), queryFn: () => fetchTicket(id) })

  if (ticket.isPending) {
    return (
      <>
        <PageHeader title="Maintenance ticket" />
        <LoadingRows rows={6} />
      </>
    )
  }

  if (ticket.isError || !ticket.data) {
    return (
      <>
        <PageHeader title="Maintenance ticket" />
        <Panel>
          <LoadFailed what="this ticket" error={ticket.error} onRetry={() => void ticket.refetch()} />
        </Panel>
      </>
    )
  }

  return <TicketDetail ticket={ticket.data} />
}

type Dialog = 'schedule' | 'complete' | 'cancel' | 'assign' | 'returnToService' | null

function TicketDetail({ ticket }: { ticket: MaintenanceTicket }) {
  const invalidate = [maintenanceKeys.detail(ticket.id), ['maintenance', 'list'], ['fleet']]
  const [dialog, setDialog] = useState<Dialog>(null)

  const start = useOperation({ run: () => startTicket(ticket.id), invalidate })
  const schedule = useOperation<string>({ run: (date) => scheduleTicket(ticket.id, date), invalidate })
  const assign = useOperation<string>({ run: (userId) => assignTicket(ticket.id, userId), invalidate })
  const cancel = useOperation<string>({ run: (reason) => cancelTicket(ticket.id, reason), invalidate })
  const complete = useOperation<{ notes: string; odometer?: number; cost?: number; parts?: string }>({
    run: ({ notes, odometer, cost, parts }) =>
      completeTicket(ticket.id, {
        resolution_notes: notes,
        ...(odometer !== undefined ? { odometer_reading: odometer } : {}),
        ...(cost !== undefined ? { actual_cost: cost } : {}),
        ...(parts ? { parts_used: parts } : {}),
      }),
    invalidate,
  })
  const returnToService = useOperation<string>({
    run: (reason) => changeBusStatus(ticket.bus_id, 'AVAILABLE', reason),
    invalidate,
  })

  const closeDialog = () => {
    setDialog(null)
    schedule.reset()
    assign.reset()
    cancel.reset()
    complete.reset()
    returnToService.reset()
  }

  const canStart = canTransition(ticket.status, 'IN_PROGRESS')
  const canSchedule = canTransition(ticket.status, 'SCHEDULED')
  const canComplete = canTransition(ticket.status, 'COMPLETED')
  const canCancel = canTransition(ticket.status, 'CANCELLED')
  const settled = isTerminal(ticket.status)
  const busOffRoad = ticket.bus?.status === 'MAINTENANCE' || ticket.bus?.status === 'BREAKDOWN'

  return (
    <>
      <PageHeader
        breadcrumb={
          <Link to="/maintenance" className="text-primary">
            ← Maintenance
          </Link>
        }
        title={ticket.issue_description}
        subtitle={`Raised ${shortDate(ticket.created_at)} by ${personName(ticket.opened_by)}`}
        actions={
          <div className="flex flex-wrap items-center gap-sm">
            <PriorityChip priority={ticket.priority} />
            <TicketStatusChip status={ticket.status} />
          </div>
        }
      />

      <div className="mb-lg flex flex-wrap gap-sm">
        <Can capability="maintenance.assign">
          <ActionButton
            label="Assign"
            icon="assign"
            disabled={settled}
            title={settled ? 'This ticket is finished.' : undefined}
            onClick={() => setDialog('assign')}
          />
        </Can>

        <Can capability="maintenance.schedule">
          <ActionButton
            label="Schedule"
            icon="schedule"
            disabled={!canSchedule}
            title={canSchedule ? undefined : `A ticket that is ${humanise(ticket.status).toLowerCase()} cannot be scheduled.`}
            onClick={() => setDialog('schedule')}
          />
        </Can>

        <Can capability="maintenance.start">
          <ActionButton
            label="Start work"
            icon="maintenance"
            busy={start.isPending}
            disabled={!canStart}
            title={canStart ? undefined : `A ticket that is ${humanise(ticket.status).toLowerCase()} cannot be started.`}
            onClick={() => void start.run()}
          />
        </Can>

        <Can capability="maintenance.complete">
          <ActionButton
            label="Complete"
            tone="primary"
            icon="success"
            disabled={!canComplete}
            title={canComplete ? undefined : 'Only work that is under way can be completed.'}
            onClick={() => setDialog('complete')}
          />
        </Can>

        <Can capability="maintenance.cancel">
          <ActionButton
            label="Cancel ticket"
            tone="destructive"
            icon="close"
            disabled={!canCancel}
            title={
              canCancel
                ? undefined
                : ticket.status === 'IN_PROGRESS'
                  ? 'Work already under way is completed with what was found, not cancelled.'
                  : 'This ticket is finished.'
            }
            onClick={() => setDialog('cancel')}
          />
        </Can>
      </div>

      {(start.failure || start.success) && (
        <div className="mb-lg">
          <OperationResult operation={start} />
        </div>
      )}

      {/*
        J10 — return to service is a *second* call, and is presented as one.
        There is no endpoint that completes a ticket and clears the bus in one
        act, and pretending otherwise would hide a decision somebody is
        accountable for.
      */}
      {ticket.status === 'COMPLETED' && busOffRoad && (
        <Can capability="bus.changeStatus">
          <section className="mb-lg rounded-md border border-primary/40 bg-primary-container/40 p-lg">
            <h2 className="flex items-center gap-sm text-title-md font-semibold">
              <Icon name="buses" size="sm" />
              This bus is still off the road
            </h2>
            <p className="mt-xs text-body text-on-surface-muted">
              The work is signed off, but {ticket.bus?.registration_number} is still{' '}
              {humanise(ticket.bus?.status)}. Returning it to service is a separate decision, and a separate
              call.
            </p>
            <div className="mt-md">
              <ActionButton
                label="Return to service"
                tone="primary"
                icon="success"
                onClick={() => setDialog('returnToService')}
              />
            </div>
          </section>
        </Can>
      )}

      <div className="grid gap-lg lg:grid-cols-[2fr_1fr]">
        <div className="flex flex-col gap-lg">
          <Panel title="The job">
            <p className="px-lg py-lg text-body whitespace-pre-wrap">{ticket.issue_description}</p>
            {ticket.resolution_notes && (
              <div className="border-t border-outline px-lg py-lg">
                <h3 className="text-label font-medium text-on-surface-muted uppercase">What was done</h3>
                <p className="mt-xs text-body whitespace-pre-wrap">{ticket.resolution_notes}</p>
              </div>
            )}
            {ticket.parts_used && (
              <div className="border-t border-outline px-lg py-lg">
                <h3 className="text-label font-medium text-on-surface-muted uppercase">Parts used</h3>
                <p className="mt-xs text-body whitespace-pre-wrap">{ticket.parts_used}</p>
              </div>
            )}
            {ticket.cancellation_reason && (
              <div className="border-t border-outline px-lg py-lg">
                <h3 className="text-label font-medium text-on-surface-muted uppercase">Why it was cancelled</h3>
                <p className="mt-xs text-body whitespace-pre-wrap">{ticket.cancellation_reason}</p>
              </div>
            )}
          </Panel>

          {(ticket.incident || ticket.inspection) && (
            <Panel title="Where it came from">
              <ul className="px-lg py-md text-body">
                {ticket.incident && (
                  <li className="flex items-center gap-sm py-xs">
                    <Icon name="incidents" size="sm" className="text-on-surface-muted" />
                    <Link to={`/incidents/${ticket.incident.id}`} className="text-primary">
                      {humanise(ticket.incident.incident_type)}
                    </Link>
                    <span className="ml-auto text-label text-on-surface-muted">
                      {humanise(ticket.incident.status)}
                    </span>
                  </li>
                )}
                {ticket.inspection && (
                  <li className="flex items-center gap-sm py-xs">
                    <Icon name="inspections" size="sm" className="text-on-surface-muted" />
                    <span>Vehicle inspection</span>
                    <span className="ml-auto text-label text-on-surface-muted">
                      {humanise(ticket.inspection.outcome)}
                    </span>
                  </li>
                )}
              </ul>
            </Panel>
          )}
        </div>

        <div className="flex flex-col gap-lg">
          <Panel title="Details">
            <FieldGrid columns={2}>
              <Field label="Bus">
                {ticket.bus ? (
                  <Link to={`/buses/${ticket.bus.id}`} className="font-mono text-primary">
                    {ticket.bus.registration_number}
                  </Link>
                ) : (
                  '—'
                )}
              </Field>
              <Field label="Assigned to">{personName(ticket.assigned_to)}</Field>
              <Field label="Scheduled">{shortDate(ticket.scheduled_date)}</Field>
              <Field label="Started">{shortDate(ticket.started_at)}</Field>
              <Field label="Completed">{shortDate(ticket.completion_date)}</Field>
              <Field label="Signed off by">{personName(ticket.completed_by)}</Field>
              <Field label="Estimated cost">{amount(ticket.estimated_cost)}</Field>
              <Field label="Actual cost">{amount(ticket.actual_cost)}</Field>
              <Field label="Odometer">
                {ticket.odometer_reading ? `${ticket.odometer_reading.toLocaleString()} km` : '—'}
              </Field>
            </FieldGrid>
          </Panel>
        </div>
      </div>

      <AssignDialog open={dialog === 'assign'} operation={assign} onClose={closeDialog} />

      <ScheduleDialog open={dialog === 'schedule'} operation={schedule} onClose={closeDialog} />

      <CompleteDialog open={dialog === 'complete'} operation={complete} onClose={closeDialog} bus={ticket.bus?.registration_number} />

      <ConfirmDialog
        open={dialog === 'cancel'}
        title="Cancel this ticket?"
        body="The fault stops holding the bus. Say why, so the record explains itself later."
        confirmLabel="Cancel ticket"
        tone="destructive"
        reason={{ label: 'Reason', field: 'reason', minLength: 5 }}
        operation={cancel}
        onClose={closeDialog}
        onConfirm={(reason) => void cancel.run(reason).then((ok) => ok && closeDialog())}
      />

      <ConfirmDialog
        open={dialog === 'returnToService'}
        title={`Return ${ticket.bus?.registration_number ?? 'this bus'} to service?`}
        body="This makes the bus available for the timetable again. It is a separate act from signing the work off, and it is recorded separately."
        confirmLabel="Return to service"
        tone="primary"
        reason={{ label: 'Note (optional but recorded)', field: 'reason', minLength: 0 }}
        operation={returnToService}
        onClose={closeDialog}
        onConfirm={(reason) => void returnToService.run(reason).then((ok) => ok && closeDialog())}
      />
    </>
  )
}

/**
 * Assignment takes a user id, so the panel has to find one.
 *
 * A search, not a directory dump: `GET /users?search=` is what the endpoint
 * offers, and listing every account in the college inside a workshop screen
 * would be a privacy decision nobody made.
 */
function AssignDialog({
  open,
  operation,
  onClose,
}: {
  open: boolean
  operation: ReturnType<typeof useOperation<string>>
  onClose: () => void
}) {
  const [search, setSearch] = useState('')
  const [selected, setSelected] = useState('')

  const people = useQuery({
    queryKey: ['users', 'assignable', search],
    queryFn: async () =>
      (
        await request<Array<{ id: string; full_name?: string; first_name?: string; last_name?: string; email: string }>>(
          '/users',
          { query: { search, is_active: 1, per_page: 10 } },
        )
      ).data,
    enabled: open && search.trim().length >= 2,
  })

  if (!open) return null

  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-black/40 p-lg">
      <div role="dialog" aria-modal="true" aria-label="Assign this ticket" className="w-full max-w-lg rounded-md border border-outline bg-surface p-xl">
        <h2 className="text-title-lg font-semibold">Assign this ticket</h2>

        <label className="mt-lg block">
          <span className="text-label font-medium text-on-surface-muted uppercase">Find a colleague</span>
          <input
            type="search"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Name or email"
            className="mt-xs h-[var(--size-control)] w-full rounded-sm border border-outline bg-surface px-md text-body"
          />
        </label>

        {search.trim().length < 2 && (
          <p className="mt-md text-label text-on-surface-muted">Type at least two characters.</p>
        )}

        {people.data && (
          <ul className="mt-md max-h-56 overflow-y-auto rounded-sm border border-outline">
            {people.data.length === 0 && <li className="p-md text-body text-on-surface-muted">Nobody matches.</li>}
            {people.data.map((person) => (
              <li key={person.id}>
                <button
                  type="button"
                  onClick={() => setSelected(person.id)}
                  className={`flex w-full items-baseline gap-sm p-md text-left text-body ${
                    selected === person.id ? 'bg-primary-container' : 'hover:bg-surface-sunken'
                  }`}
                >
                  <span className="font-semibold">
                    {person.full_name || [person.first_name, person.last_name].filter(Boolean).join(' ')}
                  </span>
                  <span className="ml-auto text-label text-on-surface-muted">{person.email}</span>
                </button>
              </li>
            ))}
          </ul>
        )}

        <div className="mt-lg">
          <OperationResult operation={operation} />
        </div>

        <div className="mt-lg flex justify-end gap-sm">
          <ActionButton label="Cancel" onClick={onClose} />
          <ActionButton
            label="Assign"
            tone="primary"
            busy={operation.isPending}
            disabled={!selected}
            title={selected ? undefined : 'Choose somebody first.'}
            onClick={() => void operation.run(selected).then((ok) => ok && onClose())}
          />
        </div>
      </div>
    </div>
  )
}

function ScheduleDialog({
  open,
  operation,
  onClose,
}: {
  open: boolean
  operation: ReturnType<typeof useOperation<string>>
  onClose: () => void
}) {
  const [date, setDate] = useState('')

  if (!open) return null

  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-black/40 p-lg">
      <div role="dialog" aria-modal="true" aria-label="Book this work in" className="w-full max-w-md rounded-md border border-outline bg-surface p-xl">
        <h2 className="text-title-lg font-semibold">Book this work in</h2>
        <p className="mt-md text-body text-on-surface-muted">
          The bus is expected in the workshop on this date.
        </p>

        <label className="mt-lg block">
          <span className="text-label font-medium text-on-surface-muted uppercase">Date</span>
          <input
            type="date"
            value={date}
            onChange={(event) => setDate(event.target.value)}
            className="mt-xs h-[var(--size-control)] w-full rounded-sm border border-outline bg-surface px-md text-body"
          />
          {operation.fieldError('scheduled_date') && (
            <span className="mt-xs block text-label text-critical">{operation.fieldError('scheduled_date')}</span>
          )}
        </label>

        <div className="mt-lg">
          <OperationResult operation={operation} />
        </div>

        <div className="mt-lg flex justify-end gap-sm">
          <ActionButton label="Cancel" onClick={onClose} />
          <ActionButton
            label="Schedule"
            tone="primary"
            busy={operation.isPending}
            disabled={!date}
            onClick={() => void operation.run(date).then((ok) => ok && onClose())}
          />
        </div>
      </div>
    </div>
  )
}

function CompleteDialog({
  open,
  operation,
  onClose,
  bus,
}: {
  open: boolean
  operation: ReturnType<typeof useOperation<{ notes: string; odometer?: number; cost?: number; parts?: string }>>
  onClose: () => void
  bus?: string
}) {
  const [notes, setNotes] = useState('')
  const [odometer, setOdometer] = useState('')
  const [cost, setCost] = useState('')
  const [parts, setParts] = useState('')

  if (!open) return null

  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-black/40 p-lg">
      <div role="dialog" aria-modal="true" aria-label="Sign this work off" className="w-full max-w-lg rounded-md border border-outline bg-surface p-xl">
        <h2 className="text-title-lg font-semibold">Sign this work off</h2>
        <p className="mt-md text-body text-on-surface-muted">
          This is the record of what was actually done to {bus ?? 'the bus'}. It does not by itself put the
          bus back on the road — that is a separate decision.
        </p>

        <label className="mt-lg block">
          <span className="text-label font-medium text-on-surface-muted uppercase">What was done</span>
          <textarea
            value={notes}
            onChange={(event) => setNotes(event.target.value)}
            rows={3}
            className="mt-xs w-full rounded-sm border border-outline bg-surface p-md text-body"
          />
          {operation.fieldError('resolution_notes') && (
            <span className="mt-xs block text-label text-critical">{operation.fieldError('resolution_notes')}</span>
          )}
        </label>

        <div className="mt-lg grid gap-md sm:grid-cols-2">
          <label className="block">
            <span className="text-label font-medium text-on-surface-muted uppercase">Odometer (km)</span>
            <input
              type="number"
              value={odometer}
              onChange={(event) => setOdometer(event.target.value)}
              className="mt-xs h-[var(--size-control)] w-full rounded-sm border border-outline bg-surface px-md text-body"
            />
            {operation.fieldError('odometer_reading') && (
              <span className="mt-xs block text-label text-critical">{operation.fieldError('odometer_reading')}</span>
            )}
          </label>
          <label className="block">
            <span className="text-label font-medium text-on-surface-muted uppercase">Actual cost</span>
            <input
              type="number"
              value={cost}
              onChange={(event) => setCost(event.target.value)}
              className="mt-xs h-[var(--size-control)] w-full rounded-sm border border-outline bg-surface px-md text-body"
            />
            {operation.fieldError('actual_cost') && (
              <span className="mt-xs block text-label text-critical">{operation.fieldError('actual_cost')}</span>
            )}
          </label>
        </div>

        <label className="mt-lg block">
          <span className="text-label font-medium text-on-surface-muted uppercase">Parts used</span>
          <textarea
            value={parts}
            onChange={(event) => setParts(event.target.value)}
            rows={2}
            className="mt-xs w-full rounded-sm border border-outline bg-surface p-md text-body"
          />
        </label>

        <div className="mt-lg">
          <OperationResult operation={operation} />
        </div>

        <div className="mt-lg flex justify-end gap-sm">
          <ActionButton label="Cancel" onClick={onClose} />
          <ActionButton
            label="Complete"
            tone="primary"
            busy={operation.isPending}
            disabled={notes.trim().length < 5}
            title={notes.trim().length < 5 ? 'Describe the work in at least 5 characters.' : undefined}
            onClick={() =>
              void operation
                .run({
                  notes: notes.trim(),
                  odometer: odometer ? Number(odometer) : undefined,
                  cost: cost ? Number(cost) : undefined,
                  parts: parts.trim() || undefined,
                })
                .then((ok) => ok && onClose())
            }
          />
        </div>
      </div>
    </div>
  )
}
