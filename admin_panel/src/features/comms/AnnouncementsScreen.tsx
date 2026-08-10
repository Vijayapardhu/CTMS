import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { StatusChip } from '@/components/StatusChip'
import { EmptyState, LoadFailed, LoadingRows, Pager, RangeLabel, RefreshButton, StaleBanner } from '@/components/Panel'
import { ActionButton, ConfirmDialog, OperationResult, useOperation } from '@/components/operations'
import { Can } from '@/auth/Can'
import {
  AUDIENCES,
  AUDIENCE_SENTENCE,
  PRIORITIES,
  announcementState,
  commsKeys,
  createAnnouncement,
  fetchAnnouncements,
  humanise,
  personName,
  publishAnnouncement,
  updateAnnouncement,
  whenText,
  withdrawAnnouncement,
  type Announcement,
  type AnnouncementAudience,
  type AnnouncementPriority,
} from './api'

/**
 * A14 Announcements.
 *
 * Drafts are visible to staff so one can be found again after it is written —
 * that is what `include_drafts` is for, and it is on by default here because a
 * transport office writing a notice needs to see the notice it wrote.
 *
 * The publish dialog names the audience in a sentence rather than a code.
 * "Publish" and "tell every student in the college" should not feel like the
 * same click.
 */
export function AnnouncementsScreen() {
  const [params, setParams] = useSearchParams()
  const queryClient = useQueryClient()
  const includeDrafts = params.get('published') !== '1'
  const page = Number(params.get('page') ?? 1)

  const announcements = useQuery({
    queryKey: commsKeys.announcements(includeDrafts, page),
    queryFn: () => fetchAnnouncements(includeDrafts, page),
  })
  const pagination = announcements.data?.pagination

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
        title="Announcements"
        subtitle="Notices to students, drivers and staff."
        actions={
          <div className="flex items-center gap-sm">
            <Can capability="announcement.create">
              <ComposeButton />
            </Can>
            <RefreshButton
              onClick={() => void queryClient.invalidateQueries({ queryKey: ['announcements'] })}
              busy={announcements.isFetching}
            />
          </div>
        }
      />

      <div className="mb-lg flex flex-wrap items-center gap-md rounded-md border border-outline bg-surface p-md">
        <button
          type="button"
          onClick={() => update({ published: includeDrafts ? '1' : undefined })}
          className="h-[var(--size-control)] rounded-sm border border-outline px-md text-body"
        >
          {includeDrafts ? 'Only what is live' : 'Include drafts and withdrawn'}
        </button>
        <span className="ml-auto">
          <RangeLabel pagination={pagination} noun="announcements" />
        </span>
      </div>

      <div className="overflow-hidden rounded-md border border-outline bg-surface">
        {announcements.isError && announcements.data && (
          <StaleBanner error={announcements.error} onRetry={() => void announcements.refetch()} />
        )}
        {announcements.isPending && <LoadingRows rows={3} />}
        {announcements.isError && !announcements.data && (
          <LoadFailed
            what="announcements"
            error={announcements.error}
            onRetry={() => void announcements.refetch()}
          />
        )}
        {announcements.data && announcements.data.rows.length === 0 && (
          <EmptyState icon="announcements" title="Nothing has been announced" />
        )}

        {announcements.data?.rows.map((announcement) => (
          <AnnouncementRow key={announcement.id} announcement={announcement} />
        ))}
      </div>

      <Pager pagination={pagination} onPage={(next) => update({ page: next })} />
    </>
  )
}

function AnnouncementRow({ announcement }: { announcement: Announcement }) {
  const invalidate = [['announcements']]
  const [dialog, setDialog] = useState<'publish' | 'withdraw' | 'edit' | null>(null)

  const publish = useOperation({ run: () => publishAnnouncement(announcement.id), invalidate })
  const withdraw = useOperation<string>({
    run: (reason) => withdrawAnnouncement(announcement.id, reason),
    invalidate,
  })

  const state = announcementState(announcement)

  const closeDialog = () => {
    setDialog(null)
    publish.reset()
    withdraw.reset()
  }

  return (
    <article className="flex flex-wrap items-start gap-md border-b border-outline p-lg last:border-0">
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-sm">
          <h2 className="text-title-md font-semibold">{announcement.title}</h2>
          {state === 'draft' && <StatusChip label="Draft" tone="neutral" icon="document" />}
          {state === 'live' && <StatusChip label="Live" tone="positive" icon="success" />}
          {state === 'withdrawn' && <StatusChip label="Withdrawn" tone="caution" icon="blocked" />}
          <StatusChip label={humanise(announcement.priority)} tone="info" />
        </div>

        <p className="mt-sm text-body whitespace-pre-wrap">{announcement.content}</p>

        <p className="mt-sm text-label text-on-surface-muted">
          To {AUDIENCE_SENTENCE[announcement.target_audience] ?? humanise(announcement.target_audience)} ·{' '}
          {personName(announcement.created_by)}
          {announcement.published_at
            ? ` · published ${whenText(announcement.published_at)}`
            : ` · written ${whenText(announcement.created_at)}`}
          {announcement.expires_at && ` · expires ${whenText(announcement.expires_at)}`}
        </p>
      </div>

      <div className="flex flex-wrap gap-sm">
        <Can capability="announcement.update">
          <ActionButton
            label="Edit"
            disabled={state === 'withdrawn'}
            title={state === 'withdrawn' ? 'A withdrawn notice is not edited back into life.' : undefined}
            onClick={() => setDialog('edit')}
          />
        </Can>
        <Can capability="announcement.publish">
          <ActionButton
            label="Publish"
            tone="primary"
            icon="send"
            disabled={state !== 'draft'}
            title={state !== 'draft' ? 'Only a draft can be published.' : undefined}
            onClick={() => setDialog('publish')}
          />
        </Can>
        <Can capability="announcement.withdraw">
          <ActionButton
            label="Withdraw"
            tone="destructive"
            disabled={state !== 'live'}
            title={state !== 'live' ? 'Only a live notice can be withdrawn.' : undefined}
            onClick={() => setDialog('withdraw')}
          />
        </Can>
      </div>

      <ConfirmDialog
        open={dialog === 'publish'}
        title="Publish this announcement?"
        // The audience in words, not a code.
        body={`This will be sent to ${AUDIENCE_SENTENCE[announcement.target_audience] ?? 'the selected audience'}. Notifications that go out cannot be recalled.`}
        confirmLabel="Publish"
        tone="primary"
        operation={publish}
        onClose={closeDialog}
        onConfirm={() => void publish.run().then((ok) => ok && closeDialog())}
      />

      <ConfirmDialog
        open={dialog === 'withdraw'}
        title="Withdraw this announcement?"
        body="It stops being visible. Notifications already sent cannot be recalled — this does not unsend them."
        confirmLabel="Withdraw"
        tone="destructive"
        reason={{ label: 'Reason', field: 'reason', minLength: 5 }}
        operation={withdraw}
        onClose={closeDialog}
        onConfirm={(reason) => void withdraw.run(reason).then((ok) => ok && closeDialog())}
      />

      {dialog === 'edit' && <EditDialog announcement={announcement} onClose={closeDialog} />}
    </article>
  )
}

function ComposeButton() {
  const [open, setOpen] = useState(false)

  return (
    <>
      <ActionButton label="Write a notice" tone="primary" icon="announcements" onClick={() => setOpen(true)} />
      {open && <EditDialog onClose={() => setOpen(false)} />}
    </>
  )
}

/**
 * One form for writing and for editing.
 *
 * It creates a **draft**. Publishing is a separate, named decision — the
 * backend keeps them apart and so does this.
 */
function EditDialog({ announcement, onClose }: { announcement?: Announcement; onClose: () => void }) {
  const [title, setTitle] = useState(announcement?.title ?? '')
  const [content, setContent] = useState(announcement?.content ?? '')
  const [audience, setAudience] = useState<AnnouncementAudience>(announcement?.target_audience ?? 'ALL')
  const [priority, setPriority] = useState<AnnouncementPriority>(announcement?.priority ?? 'MEDIUM')

  const save = useOperation<void>({
    run: () =>
      announcement
        ? updateAnnouncement(announcement.id, {
            title: title.trim(),
            content: content.trim(),
            target_audience: audience,
            priority,
          })
        : createAnnouncement({
            title: title.trim(),
            content: content.trim(),
            target_audience: audience,
            priority,
          }),
    invalidate: [['announcements']],
  })

  return (
    <div className="fixed inset-0 z-50 grid place-items-center bg-black/40 p-lg">
      <div
        role="dialog"
        aria-modal="true"
        aria-label={announcement ? 'Edit this notice' : 'Write a notice'}
        className="w-full max-w-xl rounded-md border border-outline bg-surface p-xl"
      >
        <h2 className="text-title-lg font-semibold">{announcement ? 'Edit this notice' : 'Write a notice'}</h2>
        {!announcement && (
          <p className="mt-md text-body text-on-surface-muted">
            This saves a draft. Nobody is told anything until it is published.
          </p>
        )}

        <label className="mt-lg block">
          <span className="text-label font-medium text-on-surface-muted uppercase">Title</span>
          <input
            type="text"
            value={title}
            onChange={(event) => setTitle(event.target.value)}
            className="mt-xs h-[var(--size-control)] w-full rounded-sm border border-outline bg-surface px-md text-body"
          />
          {save.fieldError('title') && (
            <span className="mt-xs block text-label text-critical">{save.fieldError('title')}</span>
          )}
        </label>

        <label className="mt-lg block">
          <span className="text-label font-medium text-on-surface-muted uppercase">Notice</span>
          <textarea
            value={content}
            onChange={(event) => setContent(event.target.value)}
            rows={5}
            className="mt-xs w-full rounded-sm border border-outline bg-surface p-md text-body"
          />
          {save.fieldError('content') && (
            <span className="mt-xs block text-label text-critical">{save.fieldError('content')}</span>
          )}
        </label>

        <div className="mt-lg grid gap-md sm:grid-cols-2">
          <label className="block">
            <span className="text-label font-medium text-on-surface-muted uppercase">Audience</span>
            <select
              value={audience}
              onChange={(event) => setAudience(event.target.value as AnnouncementAudience)}
              className="mt-xs h-[var(--size-control)] w-full rounded-sm border border-outline bg-surface px-md text-body"
            >
              {AUDIENCES.map((value) => (
                <option key={value} value={value}>
                  {humanise(value)}
                </option>
              ))}
            </select>
            <span className="mt-xs block text-label text-on-surface-muted">{AUDIENCE_SENTENCE[audience]}</span>
          </label>

          <label className="block">
            <span className="text-label font-medium text-on-surface-muted uppercase">Priority</span>
            <select
              value={priority}
              onChange={(event) => setPriority(event.target.value as AnnouncementPriority)}
              className="mt-xs h-[var(--size-control)] w-full rounded-sm border border-outline bg-surface px-md text-body"
            >
              {PRIORITIES.map((value) => (
                <option key={value} value={value}>
                  {humanise(value)}
                </option>
              ))}
            </select>
          </label>
        </div>

        <div className="mt-lg">
          <OperationResult operation={save} />
        </div>

        <div className="mt-lg flex justify-end gap-sm">
          <ActionButton label="Cancel" onClick={onClose} />
          <ActionButton
            label={announcement ? 'Save changes' : 'Save draft'}
            tone="primary"
            busy={save.isPending}
            disabled={title.trim().length < 3 || content.trim().length < 10}
            title={
              title.trim().length < 3
                ? 'The title needs at least 3 characters.'
                : content.trim().length < 10
                  ? 'The notice needs at least 10 characters.'
                  : undefined
            }
            onClick={() => void save.run().then((ok) => ok && onClose())}
          />
        </div>
      </div>
    </div>
  )
}
