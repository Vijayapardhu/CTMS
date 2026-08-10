import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { StatusChip, type StatusTone } from '@/components/StatusChip'
import { EmptyState, LoadFailed, LoadingRows, Pager, RangeLabel, RefreshButton, StaleBanner } from '@/components/Panel'
import { ActionButton, ConfirmDialog, OperationResult, useOperation } from '@/components/operations'
import { Can } from '@/auth/Can'
import { Icon } from '@/icons/Icon'
import {
  approveConsolidation,
  approveReplacement,
  consolidationCan,
  dispatchReplacement,
  executeConsolidation,
  fetchCandidates,
  fetchConsolidations,
  fetchReplacement,
  fetchReplacements,
  humanise,
  markReplacementArrived,
  notifyConsolidation,
  personName,
  proposeConsolidation,
  recoveryKeys,
  rejectConsolidation,
  rejectReplacement,
  replacementCan,
  distanceText,
  whenText,
  type Consolidation,
  type ConsolidationStatus,
  type Replacement,
  type ReplacementStatus,
} from './api'

const REPLACEMENT_TONE: Record<ReplacementStatus, StatusTone> = {
  RECOMMENDED: 'caution',
  APPROVED: 'info',
  DISPATCHED: 'info',
  ARRIVED: 'positive',
  COMPLETED: 'neutral',
  REJECTED: 'neutral',
}

const CONSOLIDATION_TONE: Record<ConsolidationStatus, StatusTone> = {
  PROPOSED: 'caution',
  APPROVED: 'info',
  EXECUTED: 'positive',
  REJECTED: 'neutral',
  EXPIRED: 'neutral',
}

/**
 * Recovery — replacement vehicles and service consolidation.
 *
 * Both are staged on purpose, and the panel keeps the stages apart. Approving
 * a replacement is not dispatching it; telling passengers a service has been
 * merged is not merging it. BR-363 turns on that order, so each step is its
 * own control with its own refusal.
 */
export function RecoveryScreen() {
  const [params, setParams] = useSearchParams()
  const queryClient = useQueryClient()
  const tab = params.get('tab') === 'consolidations' ? 'consolidations' : 'replacements'

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
        title="Recovery"
        subtitle="Replacement vehicles when a bus fails, and merges when a service is nearly empty."
        actions={
          <RefreshButton
            onClick={() => {
              void queryClient.invalidateQueries({ queryKey: ['replacements'] })
              void queryClient.invalidateQueries({ queryKey: ['consolidations'] })
            }}
          />
        }
      />

      <div className="mb-lg flex gap-xs border-b border-outline" role="tablist">
        <button
          type="button"
          role="tab"
          aria-selected={tab === 'replacements'}
          onClick={() => update({ tab: undefined })}
          className={`h-[var(--size-toolbar)] px-lg text-body ${
            tab === 'replacements' ? 'border-b-2 border-primary font-semibold text-primary' : 'text-on-surface-muted'
          }`}
        >
          Replacements
        </button>
        <button
          type="button"
          role="tab"
          aria-selected={tab === 'consolidations'}
          onClick={() => update({ tab: 'consolidations' })}
          className={`h-[var(--size-toolbar)] px-lg text-body ${
            tab === 'consolidations' ? 'border-b-2 border-primary font-semibold text-primary' : 'text-on-surface-muted'
          }`}
        >
          Consolidations
        </button>
      </div>

      {tab === 'replacements' ? (
        <Replacements params={params} update={update} />
      ) : (
        <Consolidations params={params} update={update} />
      )}
    </>
  )
}

// ── replacements ───────────────────────────────────────────────────────────

function Replacements({
  params,
  update,
}: {
  params: URLSearchParams
  update: (next: Record<string, string | number | undefined>) => void
}) {
  const status = params.get('status') ?? ''
  const open = !status && params.get('all') !== '1'
  const page = Number(params.get('page') ?? 1)

  const replacements = useQuery({
    queryKey: [...recoveryKeys.replacements(status, open), page],
    queryFn: () => fetchReplacements(status, open, page),
  })
  const pagination = replacements.data?.pagination

  return (
    <>
      <div className="mb-lg flex flex-wrap items-center gap-md rounded-md border border-outline bg-surface p-md">
        <p className="text-body text-on-surface-muted">
          {open ? 'Requests still in play.' : 'Every replacement request matching this filter.'}
        </p>
        {open ? (
          <button
            type="button"
            onClick={() => update({ all: '1' })}
            className="h-[var(--size-control)] rounded-sm border border-outline px-md text-body"
          >
            Include settled requests
          </button>
        ) : (
          <button
            type="button"
            onClick={() => update({ all: undefined, status: undefined })}
            className="h-[var(--size-control)] rounded-sm px-md text-body text-primary"
          >
            Back to open requests
          </button>
        )}
        <span className="ml-auto">
          <RangeLabel pagination={pagination} noun="requests" />
        </span>
      </div>

      <div className="overflow-hidden rounded-md border border-outline bg-surface">
        {replacements.isError && replacements.data && (
          <StaleBanner error={replacements.error} onRetry={() => void replacements.refetch()} />
        )}
        {replacements.isPending && <LoadingRows rows={3} />}
        {replacements.isError && !replacements.data && (
          <LoadFailed
            what="replacement requests"
            error={replacements.error}
            onRetry={() => void replacements.refetch()}
          />
        )}
        {replacements.data && replacements.data.rows.length === 0 && (
          <EmptyState
            icon="success"
            title={open ? 'No replacements in play' : 'Nothing matches this filter'}
            hint={open ? 'No bus is currently waiting on another to take over.' : undefined}
          />
        )}

        {replacements.data?.rows.map((replacement) => (
          <ReplacementRow key={replacement.id} replacement={replacement} />
        ))}
      </div>

      <Pager pagination={pagination} onPage={(next) => update({ page: next })} />
    </>
  )
}

function ReplacementRow({ replacement }: { replacement: Replacement }) {
  const invalidate = [['replacements'], ['incidents'], ['trips']]
  const [dialog, setDialog] = useState<'approve' | 'reject' | null>(null)

  const approve = useOperation({ run: () => approveReplacement(replacement.id), invalidate })
  const reject = useOperation<string>({ run: (reason) => rejectReplacement(replacement.id, reason), invalidate })
  const dispatch = useOperation({ run: () => dispatchReplacement(replacement.id), invalidate })
  const arrived = useOperation({ run: () => markReplacementArrived(replacement.id), invalidate })

  const closeDialog = () => {
    setDialog(null)
    approve.reset()
    reject.reset()
  }

  return (
    <article className="border-b border-outline p-lg last:border-0">
      <div className="flex flex-wrap items-start gap-md">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-sm">
            <StatusChip
              label={humanise(replacement.status)}
              tone={REPLACEMENT_TONE[replacement.status] ?? 'neutral'}
              icon="swap"
            />
            {replacement.trip && (
              <Link to={`/trips/${replacement.trip.id}`} className="text-body font-semibold text-primary">
                {replacement.trip.route?.route_name ?? 'Trip'}
              </Link>
            )}
            {replacement.incident && (
              <Link to={`/incidents/${replacement.incident.id}`} className="text-label text-primary">
                {humanise(replacement.incident.incident_type)}
              </Link>
            )}
          </div>

          <p className="mt-sm text-body">
            <span className="font-mono">{replacement.original_bus?.registration_number ?? '—'}</span>
            <Icon name="forward" size="xs" className="mx-sm inline text-on-surface-muted" />
            <span className="font-mono">{replacement.replacement_bus?.registration_number ?? 'not chosen yet'}</span>
          </p>

          {replacement.reason && <p className="mt-xs text-body text-on-surface-muted">{replacement.reason}</p>}

          <p className="mt-xs text-label text-on-surface-muted">
            {distanceText(replacement.distance_metres)} away ·{' '}
            {replacement.passengers_to_transfer ?? 0} passengers to move · raised{' '}
            {whenText(replacement.created_at)}
          </p>

          {replacement.rejection_reason && (
            <p className="mt-xs text-label text-critical">Rejected: {replacement.rejection_reason}</p>
          )}

          <ReplacementHistory id={replacement.id} />
        </div>

        <div className="flex flex-wrap gap-sm">
          <Can capability="replacement.approve">
            <ActionButton
              label="Approve"
              tone="primary"
              disabled={!replacementCan(replacement.status, 'APPROVED')}
              title={
                replacementCan(replacement.status, 'APPROVED')
                  ? undefined
                  : `A request that is ${humanise(replacement.status).toLowerCase()} cannot be approved.`
              }
              onClick={() => setDialog('approve')}
            />
          </Can>
          <Can capability="replacement.reject">
            <ActionButton
              label="Reject"
              disabled={!replacementCan(replacement.status, 'REJECTED')}
              title={
                replacementCan(replacement.status, 'REJECTED')
                  ? undefined
                  : `A request that is ${humanise(replacement.status).toLowerCase()} cannot be rejected.`
              }
              onClick={() => setDialog('reject')}
            />
          </Can>
          {/* Dispatching is executing a decision somebody else already took,
              which is why it sits a tier lower than approving. */}
          <Can capability="replacement.dispatch">
            <ActionButton
              label="Dispatch"
              icon="send"
              busy={dispatch.isPending}
              disabled={!replacementCan(replacement.status, 'DISPATCHED')}
              title={
                replacementCan(replacement.status, 'DISPATCHED')
                  ? undefined
                  : 'Only an approved request can be dispatched.'
              }
              onClick={() => void dispatch.run()}
            />
          </Can>
          <Can capability="replacement.arrived">
            <ActionButton
              label="Mark arrived"
              icon="success"
              busy={arrived.isPending}
              disabled={!replacementCan(replacement.status, 'ARRIVED')}
              title={
                replacementCan(replacement.status, 'ARRIVED')
                  ? undefined
                  : 'Only a dispatched replacement can arrive.'
              }
              onClick={() => void arrived.run()}
            />
          </Can>
        </div>
      </div>

      {(dispatch.failure || dispatch.success || arrived.failure || arrived.success) && (
        <div className="mt-md">
          <OperationResult operation={dispatch.failure || dispatch.success ? dispatch : arrived} />
        </div>
      )}

      <ConfirmDialog
        open={dialog === 'approve'}
        title="Approve this replacement?"
        body={`${replacement.replacement_bus?.registration_number ?? 'The recommended bus'} will be committed to this trip. Approving does not dispatch it — that is the next step, and somebody has to take it.`}
        confirmLabel="Approve"
        tone="primary"
        operation={approve}
        onClose={closeDialog}
        onConfirm={() => void approve.run().then((ok) => ok && closeDialog())}
      />

      <ConfirmDialog
        open={dialog === 'reject'}
        title="Reject this replacement?"
        body="The stranded passengers still need a bus. Say why this one is not it."
        confirmLabel="Reject"
        tone="destructive"
        reason={{ label: 'Reason', field: 'reason', minLength: 5 }}
        operation={reject}
        onClose={closeDialog}
        onConfirm={(reason) => void reject.run(reason).then((ok) => ok && closeDialog())}
      />
    </article>
  )
}

/**
 * Who decided, and when.
 *
 * `GET /replacements` does not load `approvedBy` — `GET /replacements/{id}`
 * does. So this is fetched on demand rather than shown as blank in every row
 * or, worse, guessed at.
 */
function ReplacementHistory({ id }: { id: string }) {
  const [open, setOpen] = useState(false)
  const detail = useQuery({
    queryKey: recoveryKeys.replacement(id),
    queryFn: () => fetchReplacement(id),
    enabled: open,
  })

  return (
    <div className="mt-sm">
      <button
        type="button"
        onClick={() => setOpen((was) => !was)}
        aria-expanded={open}
        className="text-label font-semibold text-primary"
      >
        {open ? 'Hide history' : 'History'}
      </button>

      {open && (
        <dl className="mt-sm grid gap-sm text-label sm:grid-cols-2">
          {detail.isPending && <dd className="text-on-surface-muted">Loading…</dd>}
          {detail.isError && <dd className="text-on-surface-muted">The history could not be loaded.</dd>}
          {detail.data && (
            <>
              <div>
                <dt className="text-on-surface-muted">Approved</dt>
                <dd>
                  {whenText(detail.data.approved_at)}
                  {detail.data.approved_by && ` by ${personName(detail.data.approved_by)}`}
                </dd>
              </div>
              <div>
                <dt className="text-on-surface-muted">Dispatched</dt>
                <dd>{whenText(detail.data.dispatched_at)}</dd>
              </div>
              <div>
                <dt className="text-on-surface-muted">Arrived</dt>
                <dd>{whenText(detail.data.arrived_at)}</dd>
              </div>
            </>
          )}
        </dl>
      )}
    </div>
  )
}

// ── consolidations ─────────────────────────────────────────────────────────

function Consolidations({
  params,
  update,
}: {
  params: URLSearchParams
  update: (next: Record<string, string | number | undefined>) => void
}) {
  const status = params.get('status') ?? ''
  const open = !status && params.get('all') !== '1'
  const page = Number(params.get('page') ?? 1)

  const consolidations = useQuery({
    queryKey: [...recoveryKeys.consolidations(status, open), page],
    queryFn: () => fetchConsolidations(status, open, page),
  })
  const pagination = consolidations.data?.pagination

  return (
    <>
      <div className="mb-lg flex flex-wrap items-center gap-md rounded-md border border-outline bg-surface p-md">
        <p className="text-body text-on-surface-muted">
          {open ? 'Proposals still awaiting a decision.' : 'Every proposal matching this filter.'}
        </p>
        {open ? (
          <button
            type="button"
            onClick={() => update({ all: '1' })}
            className="h-[var(--size-control)] rounded-sm border border-outline px-md text-body"
          >
            Include settled proposals
          </button>
        ) : (
          <button
            type="button"
            onClick={() => update({ all: undefined, status: undefined })}
            className="h-[var(--size-control)] rounded-sm px-md text-body text-primary"
          >
            Back to open proposals
          </button>
        )}
        <Can capability="consolidation.create">
          <span className="ml-auto">
            <ProposeButton />
          </span>
        </Can>
      </div>

      <div className="overflow-hidden rounded-md border border-outline bg-surface">
        {consolidations.isError && consolidations.data && (
          <StaleBanner error={consolidations.error} onRetry={() => void consolidations.refetch()} />
        )}
        {consolidations.isPending && <LoadingRows rows={3} />}
        {consolidations.isError && !consolidations.data && (
          <LoadFailed
            what="consolidation proposals"
            error={consolidations.error}
            onRetry={() => void consolidations.refetch()}
          />
        )}
        {consolidations.data && consolidations.data.rows.length === 0 && (
          <EmptyState
            icon="success"
            title={open ? 'Nothing proposed' : 'Nothing matches this filter'}
            hint={open ? 'No service is running empty enough to be worth merging.' : undefined}
          />
        )}

        {consolidations.data?.rows.map((consolidation) => (
          <ConsolidationRow key={consolidation.id} consolidation={consolidation} />
        ))}
      </div>

      <Pager pagination={pagination} onPage={(next) => update({ page: next })} />
    </>
  )
}

function ConsolidationRow({ consolidation }: { consolidation: Consolidation }) {
  const invalidate = [['consolidations'], ['trips']]
  const [dialog, setDialog] = useState<'approve' | 'reject' | 'notify' | 'execute' | null>(null)

  const approve = useOperation({ run: () => approveConsolidation(consolidation.id), invalidate })
  const reject = useOperation<string>({ run: (reason) => rejectConsolidation(consolidation.id, reason), invalidate })
  const notify = useOperation({ run: () => notifyConsolidation(consolidation.id), invalidate })
  const execute = useOperation({ run: () => executeConsolidation(consolidation.id), invalidate })

  const closeDialog = () => {
    setDialog(null)
    approve.reset()
    reject.reset()
    notify.reset()
    execute.reset()
  }

  const notified = Boolean(consolidation.passengers_notified_at)

  return (
    <article className="border-b border-outline p-lg last:border-0">
      <div className="flex flex-wrap items-start gap-md">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-sm">
            <StatusChip
              label={humanise(consolidation.status)}
              tone={CONSOLIDATION_TONE[consolidation.status] ?? 'neutral'}
            />
            {notified && <StatusChip label="Passengers told" tone="info" icon="send" />}
          </div>

          <p className="mt-sm text-body">
            <Link to={`/trips/${consolidation.source_trip_id}`} className="font-semibold text-primary">
              {consolidation.source_trip?.route?.route_name ?? 'Source trip'}
            </Link>
            <span className="mx-sm text-on-surface-muted">merges into</span>
            <Link to={`/trips/${consolidation.target_trip_id}`} className="font-semibold text-primary">
              {consolidation.target_trip?.route?.route_name ?? 'Target trip'}
            </Link>
          </p>

          <p className="mt-xs text-label text-on-surface-muted">
            {consolidation.source_passengers ?? 0} + {consolidation.target_passengers ?? 0} passengers
            {consolidation.target_capacity !== null && ` into ${consolidation.target_capacity} seats`}
            {consolidation.expires_at && ` · expires ${whenText(consolidation.expires_at)}`}
            {consolidation.decided_by && ` · decided by ${personName(consolidation.decided_by)}`}
          </p>

          {consolidation.reason && <p className="mt-xs text-body text-on-surface-muted">{consolidation.reason}</p>}
          {consolidation.rejection_reason && (
            <p className="mt-xs text-label text-critical">Rejected: {consolidation.rejection_reason}</p>
          )}
        </div>

        <div className="flex flex-wrap gap-sm">
          <Can capability="consolidation.approve">
            <ActionButton
              label="Approve"
              tone="primary"
              disabled={!consolidationCan(consolidation.status, 'APPROVED')}
              title={
                consolidationCan(consolidation.status, 'APPROVED')
                  ? undefined
                  : `A proposal that is ${humanise(consolidation.status).toLowerCase()} cannot be approved.`
              }
              onClick={() => setDialog('approve')}
            />
          </Can>
          <Can capability="consolidation.reject">
            <ActionButton
              label="Reject"
              disabled={!consolidationCan(consolidation.status, 'REJECTED')}
              title={
                consolidationCan(consolidation.status, 'REJECTED')
                  ? undefined
                  : `A proposal that is ${humanise(consolidation.status).toLowerCase()} cannot be rejected.`
              }
              onClick={() => setDialog('reject')}
            />
          </Can>
          {/* BR-363: passengers are told before the service they are waiting
              for disappears, not after. */}
          <Can capability="consolidation.notify">
            <ActionButton
              label="Tell passengers"
              icon="send"
              busy={notify.isPending}
              disabled={consolidation.status !== 'APPROVED' || notified}
              title={
                notified
                  ? `Already sent ${whenText(consolidation.passengers_notified_at)}.`
                  : consolidation.status !== 'APPROVED'
                    ? 'Only an approved merge is announced.'
                    : undefined
              }
              onClick={() => setDialog('notify')}
            />
          </Can>
          <Can capability="consolidation.execute">
            <ActionButton
              label="Execute"
              disabled={!consolidationCan(consolidation.status, 'EXECUTED')}
              title={
                consolidationCan(consolidation.status, 'EXECUTED')
                  ? undefined
                  : 'Only an approved merge can be carried out.'
              }
              onClick={() => setDialog('execute')}
            />
          </Can>
        </div>
      </div>

      <ConfirmDialog
        open={dialog === 'approve'}
        title="Approve this merge?"
        body="Approving does not move anybody. The passengers still have to be told, and the merge still has to be carried out."
        confirmLabel="Approve"
        tone="primary"
        operation={approve}
        onClose={closeDialog}
        onConfirm={() => void approve.run().then((ok) => ok && closeDialog())}
      />

      <ConfirmDialog
        open={dialog === 'reject'}
        title="Reject this merge?"
        body="Both services keep running as timetabled."
        confirmLabel="Reject"
        tone="destructive"
        reason={{ label: 'Reason', field: 'reason', minLength: 5 }}
        operation={reject}
        onClose={closeDialog}
        onConfirm={(reason) => void reject.run(reason).then((ok) => ok && closeDialog())}
      />

      <ConfirmDialog
        open={dialog === 'notify'}
        title="Tell the passengers?"
        body="Everybody booked on the merged service is notified that their bus has changed. This cannot be unsent."
        confirmLabel="Send"
        tone="primary"
        operation={notify}
        onClose={closeDialog}
        onConfirm={() => void notify.run().then((ok) => ok && closeDialog())}
      />

      <ConfirmDialog
        open={dialog === 'execute'}
        title="Carry out this merge?"
        body={
          notified
            ? 'The passengers have been told. The source service will be cancelled and its passengers moved.'
            : 'The passengers have NOT been told yet. Merging now means somebody waits at a stop for a bus that is not coming.'
        }
        confirmLabel="Execute merge"
        tone={notified ? 'primary' : 'destructive'}
        operation={execute}
        onClose={closeDialog}
        onConfirm={() => void execute.run().then((ok) => ok && closeDialog())}
      />
    </article>
  )
}

/**
 * Proposing a merge, from the analysis the backend already runs.
 *
 * The candidate list is `GET /consolidations/candidates` — the server's own
 * pairing, asked for on demand. The panel does not pair trips itself.
 */
function ProposeButton() {
  const [open, setOpen] = useState(false)
  const [pair, setPair] = useState('')
  const [reason, setReason] = useState('')

  const candidates = useQuery({ queryKey: recoveryKeys.candidates, queryFn: fetchCandidates, enabled: open })

  const propose = useOperation<void>({
    run: () => {
      const [source, target] = pair.split('|')

      return proposeConsolidation({
        source_trip_id: source,
        target_trip_id: target,
        ...(reason.trim() ? { reason: reason.trim() } : {}),
      })
    },
    invalidate: [['consolidations']],
  })

  return (
    <>
      <ActionButton label="Propose a merge" icon="swap" onClick={() => setOpen(true)} />

      {open && (
        <div className="fixed inset-0 z-50 grid place-items-center bg-black/40 p-lg">
          <div
            role="dialog"
            aria-modal="true"
            aria-label="Propose a merge"
            className="w-full max-w-lg rounded-md border border-outline bg-surface p-xl"
          >
            <h2 className="text-title-lg font-semibold">Propose a merge</h2>
            <p className="mt-md text-body text-on-surface-muted">
              These pairs are the server's own analysis of which services are running empty enough to combine.
            </p>

            {candidates.isPending && <div className="mt-lg h-20 animate-pulse rounded-sm bg-surface-sunken" />}
            {candidates.isError && (
              <p className="mt-lg text-body text-on-surface-muted">The candidate analysis could not be loaded.</p>
            )}
            {candidates.data?.length === 0 && (
              <p className="mt-lg text-body text-on-surface-muted">
                Nothing is running empty enough to be worth merging right now.
              </p>
            )}

            {candidates.data && candidates.data.length > 0 && (
              <label className="mt-lg block">
                <span className="text-label font-medium text-on-surface-muted uppercase">Pair</span>
                <select
                  value={pair}
                  onChange={(event) => setPair(event.target.value)}
                  className="mt-xs h-[var(--size-control)] w-full rounded-sm border border-outline bg-surface px-md text-body"
                >
                  <option value="">Choose a pair</option>
                  {candidates.data.map((candidate) => (
                    <option
                      key={`${candidate.source_trip_id}|${candidate.target_trip_id}`}
                      value={`${candidate.source_trip_id}|${candidate.target_trip_id}`}
                    >
                      {candidate.source_route ?? 'Source'} ({candidate.source_passengers ?? 0}) →{' '}
                      {candidate.target_route ?? 'Target'} ({candidate.target_passengers ?? 0} of{' '}
                      {candidate.target_capacity ?? '?'})
                    </option>
                  ))}
                </select>
              </label>
            )}

            <label className="mt-lg block">
              <span className="text-label font-medium text-on-surface-muted uppercase">Reason (optional)</span>
              <textarea
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                rows={2}
                className="mt-xs w-full rounded-sm border border-outline bg-surface p-md text-body"
              />
              {propose.fieldError('reason') && (
                <span className="mt-xs block text-label text-critical">{propose.fieldError('reason')}</span>
              )}
            </label>

            <div className="mt-lg">
              <OperationResult operation={propose} />
            </div>

            <div className="mt-lg flex justify-end gap-sm">
              <ActionButton
                label="Cancel"
                onClick={() => {
                  setOpen(false)
                  propose.reset()
                }}
              />
              <ActionButton
                label="Propose"
                tone="primary"
                busy={propose.isPending}
                disabled={!pair}
                title={pair ? undefined : 'Choose a pair first.'}
                onClick={() => void propose.run().then((ok) => ok && setOpen(false))}
              />
            </div>
          </div>
        </div>
      )}
    </>
  )
}
