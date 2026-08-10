import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { StatusChip } from '@/components/StatusChip'
import { Field, FieldGrid, LoadFailed, LoadingRows, Panel } from '@/components/Panel'
import { ActionButton, ConfirmDialog, OperationResult, useOperation } from '@/components/operations'
import { Icon } from '@/icons/Icon'
import { Can } from '@/auth/Can'
import { IncidentStatusChip, SeverityChip } from './IncidentsScreen'
import {
  acknowledgeIncident,
  addIncidentNote,
  cancelIncident,
  canTransition,
  closeIncident,
  fetchIncident,
  humanise,
  incidentKeys,
  personName,
  resolveIncident,
  whenText,
  type Incident,
} from './api'

/**
 * A9 Incident Details.
 *
 * Every action here is gated by a generated capability, and two of them carry a
 * resource scope: the driver who **reported** an incident may annotate and
 * withdraw it, which is not something an access level can express. That
 * distinction is the whole of G3-3 — reading an incident and writing on its
 * record are different permissions.
 *
 * The state machine is mirrored only to disable a control and say why. The
 * server decides; a 409 arriving anyway is rendered in the server's own words.
 */
export function IncidentDetailScreen() {
  const { id = '' } = useParams()
  const incident = useQuery({ queryKey: incidentKeys.detail(id), queryFn: () => fetchIncident(id) })

  if (incident.isPending) {
    return (
      <>
        <PageHeader title="Incident" />
        <LoadingRows rows={6} />
      </>
    )
  }

  if (incident.isError || !incident.data) {
    return (
      <>
        <PageHeader title="Incident" />
        <Panel>
          <LoadFailed what="this incident" error={incident.error} onRetry={() => void incident.refetch()} />
        </Panel>
      </>
    )
  }

  return <IncidentDetail incident={incident.data} />
}

function IncidentDetail({ incident }: { incident: Incident }) {
  const invalidate = [incidentKeys.detail(incident.id), ['incidents', 'list']]
  const reporterScope = { reportedById: incident.reported_by?.id }

  const acknowledge = useOperation({ run: () => acknowledgeIncident(incident.id), invalidate })
  const resolve = useOperation<string>({ run: (notes) => resolveIncident(incident.id, notes), invalidate })
  const close = useOperation({ run: () => closeIncident(incident.id), invalidate })
  const cancel = useOperation<string>({ run: (note) => cancelIncident(incident.id, note), invalidate })
  const note = useOperation<string>({ run: (text) => addIncidentNote(incident.id, text), invalidate })

  const [dialog, setDialog] = useState<'resolve' | 'close' | 'cancel' | null>(null)
  const [noteText, setNoteText] = useState('')

  const closeDialog = () => {
    setDialog(null)
    resolve.reset()
    close.reset()
    cancel.reset()
  }

  const canAcknowledge = canTransition(incident.status, 'ACKNOWLEDGED')
  const canResolve = canTransition(incident.status, 'RESOLVED')
  const canClose = canTransition(incident.status, 'CLOSED')
  const settled = incident.status === 'CLOSED'

  return (
    <>
      <PageHeader
        breadcrumb={
          <Link to="/incidents" className="text-primary">
            ← Incidents
          </Link>
        }
        title={humanise(incident.incident_type)}
        subtitle={`Reported ${whenText(incident.reported_at)} by ${personName(incident.reported_by)}`}
        actions={
          <div className="flex flex-wrap items-center gap-sm">
            <SeverityChip severity={incident.severity} />
            <IncidentStatusChip status={incident.status} />
            {incident.incident_class === 'LIFE_SAFETY' && (
              <StatusChip label="Life safety" tone="critical" icon="sos" />
            )}
            {incident.was_cancelled && <StatusChip label="False alarm" tone="neutral" icon="blocked" />}
          </div>
        }
      />

      <div className="mb-lg flex flex-wrap gap-sm">
        <Can capability="incident.acknowledge">
          <ActionButton
            label="Acknowledge"
            icon="eta"
            busy={acknowledge.isPending}
            disabled={!canAcknowledge}
            title={canAcknowledge ? undefined : `An incident that is ${humanise(incident.status).toLowerCase()} cannot be acknowledged.`}
            onClick={() => void acknowledge.run()}
          />
        </Can>

        <Can capability="incident.resolve">
          <ActionButton
            label="Resolve"
            tone="primary"
            icon="success"
            disabled={!canResolve}
            title={canResolve ? undefined : `An incident that is ${humanise(incident.status).toLowerCase()} cannot be resolved.`}
            onClick={() => setDialog('resolve')}
          />
        </Can>

        <Can capability="incident.close">
          <ActionButton
            label="Close"
            icon="blocked"
            disabled={!canClose}
            title={canClose ? undefined : 'Only a resolved incident can be closed.'}
            onClick={() => setDialog('close')}
          />
        </Can>

        {/* Reporter scope: the driver who raised it may withdraw it, and so may
            a supervisor. Neither may do so once it is closed. */}
        <Can capability="incident.cancel" resource={reporterScope}>
          <ActionButton
            label="Record as false alarm"
            tone="destructive"
            icon="close"
            disabled={settled}
            title={settled ? 'A closed incident cannot be withdrawn.' : undefined}
            onClick={() => setDialog('cancel')}
          />
        </Can>
      </div>

      {(acknowledge.failure || acknowledge.success) && (
        <div className="mb-lg">
          <OperationResult operation={acknowledge} />
        </div>
      )}

      <div className="grid gap-lg lg:grid-cols-[2fr_1fr]">
        <div className="flex flex-col gap-lg">
          <Panel title="What was reported">
            <p className="px-lg py-lg text-body whitespace-pre-wrap">{incident.description}</p>
            {incident.resolution_notes && (
              <div className="border-t border-outline px-lg py-lg">
                <h3 className="text-label font-medium text-on-surface-muted uppercase">Resolution</h3>
                <p className="mt-xs text-body whitespace-pre-wrap">{incident.resolution_notes}</p>
              </div>
            )}
            {incident.was_cancelled && incident.cancellation_note && (
              <div className="border-t border-outline px-lg py-lg">
                <h3 className="text-label font-medium text-on-surface-muted uppercase">
                  Withdrawn as a false alarm
                </h3>
                {/* Recorded, never erased — BR-355. */}
                <p className="mt-xs text-body whitespace-pre-wrap">{incident.cancellation_note}</p>
              </div>
            )}
          </Panel>

          <Panel title="Notes" action={<span className="text-label text-on-surface-muted">{incident.notes?.length ?? 0}</span>}>
            {(incident.notes?.length ?? 0) === 0 ? (
              <p className="px-lg py-lg text-body text-on-surface-muted">Nothing has been added yet.</p>
            ) : (
              <ol className="px-lg py-md">
                {incident.notes?.map((entry) => (
                  <li key={entry.id} className="border-b border-outline py-md last:border-0">
                    <p className="text-body whitespace-pre-wrap">{entry.note}</p>
                    <p className="mt-xs text-label text-on-surface-muted">
                      {personName(entry.author)} · {whenText(entry.created_at)}
                    </p>
                  </li>
                ))}
              </ol>
            )}

            <Can capability="incident.note.create" resource={reporterScope}>
              <form
                className="border-t border-outline p-lg"
                onSubmit={(event) => {
                  event.preventDefault()
                  void note.run(noteText.trim()).then((ok) => {
                    if (ok) setNoteText('')
                  })
                }}
              >
                <label className="block">
                  <span className="text-label font-medium text-on-surface-muted uppercase">Add a note</span>
                  <textarea
                    value={noteText}
                    onChange={(event) => setNoteText(event.target.value)}
                    rows={3}
                    aria-invalid={Boolean(note.fieldError('note'))}
                    className="mt-xs w-full rounded-sm border border-outline bg-surface p-md text-body"
                  />
                </label>
                {note.fieldError('note') && (
                  <p className="mt-xs text-label text-critical">{note.fieldError('note')}</p>
                )}
                <div className="mt-md flex items-center gap-md">
                  <ActionButton
                    label="Add note"
                    tone="primary"
                    busy={note.isPending}
                    disabled={noteText.trim().length < 3}
                    title={noteText.trim().length < 3 ? 'At least 3 characters.' : undefined}
                    onClick={() => {
                      void note.run(noteText.trim()).then((ok) => {
                        if (ok) setNoteText('')
                      })
                    }}
                  />
                  <div className="min-w-0 flex-1">
                    <OperationResult operation={note} />
                  </div>
                </div>
              </form>
            </Can>
          </Panel>
        </div>

        <div className="flex flex-col gap-lg">
          <Panel title="Where and what">
            <FieldGrid columns={2}>
              <Field label="Bus">
                {incident.bus ? (
                  <Link to={`/buses/${incident.bus.id}`} className="font-mono text-primary">
                    {incident.bus.registration_number}
                  </Link>
                ) : (
                  '—'
                )}
              </Field>
              <Field label="Driver">{personName(incident.driver?.user)}</Field>
              <Field label="Trip">
                {incident.trip ? (
                  <Link to={`/trips/${incident.trip.id}`} className="text-primary">
                    {incident.trip.route?.route_name ?? 'Trip'}
                  </Link>
                ) : (
                  '—'
                )}
              </Field>
              <Field label="Class">{humanise(incident.incident_class)}</Field>
              <Field label="Passengers aboard">{incident.passengers_aboard ?? '—'}</Field>
              <Field label="Vehicle can continue">
                {incident.vehicle_can_continue === null
                  ? '—'
                  : incident.vehicle_can_continue
                    ? 'Yes'
                    : 'No'}
              </Field>
              <Field label="Location">
                {/* Coordinates as recorded. No reverse geocoding: the backend
                    does not provide a place name and inventing one here would
                    put a guess into an incident record. */}
                {incident.latitude !== null && incident.longitude !== null
                  ? `${Number(incident.latitude).toFixed(5)}, ${Number(incident.longitude).toFixed(5)}`
                  : 'Not recorded'}
              </Field>
            </FieldGrid>
          </Panel>

          <Panel title="Timeline">
            <ol className="px-lg py-md">
              <TimelineRow label="Reported" at={incident.reported_at} />
              <TimelineRow label="Acknowledged" at={incident.acknowledged_at} />
              <TimelineRow label="Escalated" at={incident.escalated_at} />
              <TimelineRow label="Resolved" at={incident.resolved_at} />
            </ol>
          </Panel>

          {(incident.maintenance_ticket || incident.replacement) && (
            <Panel title="What it opened">
              <ul className="px-lg py-md text-body">
                {incident.maintenance_ticket && (
                  <li className="flex items-center gap-sm py-xs">
                    <Icon name="maintenance" size="sm" className="text-on-surface-muted" />
                    <Link to={`/maintenance/${incident.maintenance_ticket.id}`} className="text-primary">
                      Maintenance ticket
                    </Link>
                    <span className="ml-auto text-label text-on-surface-muted">
                      {humanise(incident.maintenance_ticket.status)}
                    </span>
                  </li>
                )}
                {incident.replacement && (
                  <li className="flex items-center gap-sm py-xs">
                    <Icon name="swap" size="sm" className="text-on-surface-muted" />
                    <Link to="/replacements" className="text-primary">
                      Replacement vehicle
                    </Link>
                    <span className="ml-auto text-label text-on-surface-muted">
                      {humanise(incident.replacement.status)}
                    </span>
                  </li>
                )}
              </ul>
            </Panel>
          )}
        </div>
      </div>

      <ConfirmDialog
        open={dialog === 'resolve'}
        title="Resolve this incident?"
        body="Write what was actually done. This becomes part of the incident record."
        confirmLabel="Resolve"
        tone="primary"
        reason={{
          label: 'Resolution notes',
          field: 'resolution_notes',
          minLength: 10,
          hint: 'At least 10 characters.',
        }}
        operation={resolve}
        onClose={closeDialog}
        onConfirm={(notes) => void resolve.run(notes).then((ok) => ok && closeDialog())}
      />

      <ConfirmDialog
        open={dialog === 'close'}
        title="Close this incident?"
        body="Closing ends the record. Nothing further can be added to it."
        confirmLabel="Close incident"
        operation={close}
        onClose={closeDialog}
        onConfirm={() => void close.run().then((ok) => ok && closeDialog())}
      />

      <ConfirmDialog
        open={dialog === 'cancel'}
        title="Record this as a false alarm?"
        body="Others may already have acted on this alert. The original report is retained either way — this adds a withdrawal, it does not erase anything."
        confirmLabel="Record as false alarm"
        tone="destructive"
        reason={{ label: 'Why was it a false alarm?', field: 'note', minLength: 3 }}
        operation={cancel}
        onClose={closeDialog}
        onConfirm={(text) => void cancel.run(text).then((ok) => ok && closeDialog())}
      />
    </>
  )
}

function TimelineRow({ label, at }: { label: string; at: string | null }) {
  return (
    <li className="flex items-baseline gap-md border-b border-outline py-sm text-body last:border-0">
      <span className={at ? '' : 'text-on-surface-muted'}>{label}</span>
      <span className="ml-auto text-on-surface-muted">{at ? whenText(at) : 'Not yet'}</span>
    </li>
  )
}
