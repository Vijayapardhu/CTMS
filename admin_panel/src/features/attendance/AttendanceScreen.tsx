import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { StatusChip } from '@/components/StatusChip'
import { EmptyState, LoadFailed, LoadingRows, Pager, RangeLabel, RefreshButton, StaleBanner } from '@/components/Panel'
import { ActionButton, ConfirmDialog, useOperation } from '@/components/operations'
import { Can } from '@/auth/Can'
import {
  attendanceKeys,
  fetchDiscrepancies,
  personName,
  reviewDiscrepancy,
  whenText,
  type AttendanceDiscrepancy,
} from './api'

/**
 * Attendance discrepancies.
 *
 * A driver's headcount and the boarding record disagree. Reviewing one
 * **settles** the disagreement — BR-266 — and changes neither figure. That is
 * the sentence somebody reads before pressing the button, because a review that
 * quietly amended the evidence of what happened on a trip would be a different
 * and much worse thing.
 *
 * Review is SUPPORT: day-to-day supervision. Amending a trip record is
 * OPERATIONS and lives on the trip itself (BR-258).
 */
export function AttendanceScreen() {
  const [params, setParams] = useSearchParams()
  const queryClient = useQueryClient()

  const open = params.get('all') !== '1'
  const page = Number(params.get('page') ?? 1)

  const discrepancies = useQuery({
    queryKey: attendanceKeys.discrepancies(open, page),
    queryFn: () => fetchDiscrepancies(open, page),
  })
  const pagination = discrepancies.data?.pagination

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
        title="Attendance"
        subtitle="Where a driver's headcount and the boarding record disagree."
        actions={
          <RefreshButton
            onClick={() => void queryClient.invalidateQueries({ queryKey: ['attendance'] })}
            busy={discrepancies.isFetching}
          />
        }
      />

      <div className="mb-lg flex flex-wrap items-center gap-md rounded-md border border-outline bg-surface p-md">
        <p className="text-body text-on-surface-muted">
          {open ? 'Disagreements nobody has settled yet.' : 'Every recorded disagreement.'}
        </p>
        <button
          type="button"
          onClick={() => update({ all: open ? '1' : undefined })}
          className="h-[var(--size-control)] rounded-sm border border-outline px-md text-body"
        >
          {open ? 'Include settled' : 'Only unsettled'}
        </button>
        <span className="ml-auto">
          <RangeLabel pagination={pagination} noun="discrepancies" />
        </span>
      </div>

      <div className="overflow-hidden rounded-md border border-outline bg-surface">
        {discrepancies.isError && discrepancies.data && (
          <StaleBanner error={discrepancies.error} onRetry={() => void discrepancies.refetch()} />
        )}
        {discrepancies.isPending && <LoadingRows rows={3} />}
        {discrepancies.isError && !discrepancies.data && (
          <LoadFailed
            what="attendance discrepancies"
            error={discrepancies.error}
            onRetry={() => void discrepancies.refetch()}
          />
        )}
        {discrepancies.data && discrepancies.data.rows.length === 0 && (
          <EmptyState
            icon="success"
            title={open ? 'Nothing to settle' : 'No discrepancies recorded'}
            hint={open ? 'Every headcount matches the boarding record.' : undefined}
          />
        )}

        {discrepancies.data?.rows.map((discrepancy) => (
          <DiscrepancyRow key={discrepancy.id} discrepancy={discrepancy} />
        ))}
      </div>

      <Pager pagination={pagination} onPage={(next) => update({ page: next })} />
    </>
  )
}

function DiscrepancyRow({ discrepancy }: { discrepancy: AttendanceDiscrepancy }) {
  const [open, setOpen] = useState(false)
  const review = useOperation<string>({
    run: (note) => reviewDiscrepancy(discrepancy.id, note),
    invalidate: [['attendance']],
  })

  const settled = discrepancy.status !== 'OPEN'

  return (
    <article className="flex flex-wrap items-start gap-md border-b border-outline p-lg last:border-0">
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-sm">
          <StatusChip
            label={settled ? 'Settled' : 'Unsettled'}
            tone={settled ? 'neutral' : 'caution'}
            icon={settled ? 'success' : 'warning'}
          />
          {discrepancy.trip && (
            <Link to={`/trips/${discrepancy.trip.id}`} className="text-body font-semibold text-primary">
              {discrepancy.trip.route?.route_name ?? 'Trip'}
            </Link>
          )}
          <span className="text-label text-on-surface-muted">
            {personName(discrepancy.trip?.driver?.user)}
          </span>
        </div>

        <p className="mt-sm text-body">
          Driver counted <strong>{discrepancy.headcount}</strong>; the boarding record has{' '}
          <strong>{discrepancy.boarding_event_count}</strong> —{' '}
          <span className={discrepancy.difference === 0 ? '' : 'font-semibold text-caution'}>
            {discrepancy.difference > 0 ? '+' : ''}
            {discrepancy.difference}
          </span>
        </p>

        <p className="mt-xs text-label text-on-surface-muted">Raised {whenText(discrepancy.created_at)}</p>

        {discrepancy.review_note && (
          <p className="mt-sm rounded-sm bg-surface-sunken p-md text-body">
            {discrepancy.review_note}
            <span className="mt-xs block text-label text-on-surface-muted">
              {personName(discrepancy.reviewed_by)} · {whenText(discrepancy.reviewed_at)}
            </span>
          </p>
        )}
      </div>

      <Can capability="attendance.review">
        <ActionButton
          label="Settle"
          tone="primary"
          disabled={settled}
          title={settled ? 'This disagreement has already been settled.' : undefined}
          onClick={() => setOpen(true)}
        />
      </Can>

      <ConfirmDialog
        open={open}
        title="Settle this disagreement?"
        // BR-266, in the words of somebody about to press it.
        body="Write what actually happened. Both original figures stay exactly as they are — this records the explanation, it does not amend the count."
        confirmLabel="Settle"
        tone="primary"
        reason={{ label: 'What happened', field: 'note', minLength: 5 }}
        operation={review}
        onClose={() => {
          setOpen(false)
          review.reset()
        }}
        onConfirm={(note) =>
          void review.run(note).then((ok) => {
            if (ok) setOpen(false)
          })
        }
      />
    </article>
  )
}
