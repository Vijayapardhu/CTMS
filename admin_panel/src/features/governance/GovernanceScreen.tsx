import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useLocation, useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { StatusChip } from '@/components/StatusChip'
import { EmptyState, LoadFailed, LoadingRows, Pager, Panel, RangeLabel, StaleBanner } from '@/components/Panel'
import { Icon } from '@/icons/Icon'
import {
  fetchAccessLog,
  fetchAuditEntry,
  fetchAuditLog,
  fetchRetentionRuns,
  governanceKeys,
  humanise,
  personName,
  redact,
  whenText,
  type AuditFilters,
} from './api'

type Tab = 'audit' | 'access' | 'retention'

/**
 * A16 Governance.
 *
 * Three tabs that are never merged. The **audit log** is about change: who did
 * what to which row. The **data access log** is about reading: who looked at
 * whose personal data and why. **Retention** is about deletion: what the
 * scheduled purge matched, and what it refused.
 *
 * Everything here is read-only, and not as a panel decision — the backend has
 * no write endpoint for `audit_logs` at all. A trail is evidence only for as
 * long as nobody can reach in and adjust it, so there is no edit control to
 * offer and no delete to hide.
 *
 * Operating vehicles does not confer this. It is SUPER_ADMIN, enforced by
 * `access:SUPER_ADMIN` on every one of these routes.
 */
export function GovernanceScreen() {
  const location = useLocation()
  const [params, setParams] = useSearchParams()

  // `/admin/access-log` lands directly on the middle tab.
  const fromPath: Tab = location.pathname.endsWith('access-log') ? 'access' : 'audit'
  const tab = ((params.get('tab') as Tab | null) ?? fromPath) as Tab

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
        title="Governance"
        subtitle="What changed, who read what, and what has been purged."
      />

      <p className="mb-lg flex items-start gap-sm rounded-md border border-outline bg-surface p-md text-body">
        <Icon name="audit" size="sm" className="mt-xs text-on-surface-muted" />
        <span>
          All three records are read-only. The backend has no endpoint that edits or deletes them, which is
          what makes them evidence.
        </span>
      </p>

      <div className="mb-lg flex gap-xs border-b border-outline" role="tablist">
        {(
          [
            ['audit', 'Audit trail'],
            ['access', 'Data access'],
            ['retention', 'Retention'],
          ] as Array<[Tab, string]>
        ).map(([value, label]) => (
          <button
            key={value}
            type="button"
            role="tab"
            aria-selected={tab === value}
            onClick={() => update({ tab: value })}
            className={`h-[var(--size-toolbar)] px-lg text-body ${
              tab === value ? 'border-b-2 border-primary font-semibold text-primary' : 'text-on-surface-muted'
            }`}
          >
            {label}
          </button>
        ))}
      </div>

      {tab === 'audit' && <AuditTrail params={params} update={update} />}
      {tab === 'access' && <AccessLog params={params} update={update} />}
      {tab === 'retention' && <Retention params={params} update={update} />}
    </>
  )
}

type TabProps = {
  params: URLSearchParams
  update: (next: Record<string, string | number | undefined>) => void
}

function AuditTrail({ params, update }: TabProps) {
  const filters: AuditFilters = {
    action: params.get('action') ?? '',
    table_name: params.get('table') ?? '',
    from: params.get('from') ?? '',
    to: params.get('to') ?? '',
    page: Number(params.get('page') ?? 1),
  }

  const entries = useQuery({ queryKey: governanceKeys.audit(filters), queryFn: () => fetchAuditLog(filters) })
  const [expanded, setExpanded] = useState<string | null>(null)

  return (
    <>
      <div className="mb-lg flex flex-wrap items-end gap-md rounded-md border border-outline bg-surface p-md">
        {/* Only the filters `AuditController::index` validates. */}
        <label className="flex flex-col gap-xs">
          <span className="text-label font-medium text-on-surface-muted uppercase">Action</span>
          <input
            type="search"
            defaultValue={filters.action}
            placeholder="e.g. TRIP_CANCELLED"
            onChange={(event) => update({ action: event.target.value })}
            className="h-[var(--size-control)] w-56 rounded-sm border border-outline bg-surface px-md text-body"
          />
        </label>
        <label className="flex flex-col gap-xs">
          <span className="text-label font-medium text-on-surface-muted uppercase">Table</span>
          <input
            type="search"
            defaultValue={filters.table_name}
            placeholder="e.g. trips"
            onChange={(event) => update({ table: event.target.value })}
            className="h-[var(--size-control)] w-40 rounded-sm border border-outline bg-surface px-md text-body"
          />
        </label>
        <label className="flex flex-col gap-xs">
          <span className="text-label font-medium text-on-surface-muted uppercase">From</span>
          <input
            type="date"
            value={filters.from}
            onChange={(event) => update({ from: event.target.value })}
            className="h-[var(--size-control)] rounded-sm border border-outline bg-surface px-md text-body"
          />
        </label>
        <label className="flex flex-col gap-xs">
          <span className="text-label font-medium text-on-surface-muted uppercase">To</span>
          <input
            type="date"
            value={filters.to}
            onChange={(event) => update({ to: event.target.value })}
            className="h-[var(--size-control)] rounded-sm border border-outline bg-surface px-md text-body"
          />
        </label>
        <span className="ml-auto">
          <RangeLabel pagination={entries.data?.pagination} noun="entries" />
        </span>
      </div>

      <Panel>
        {entries.isError && entries.data && (
          <StaleBanner error={entries.error} onRetry={() => void entries.refetch()} />
        )}
        {entries.isPending && <LoadingRows />}
        {entries.isError && !entries.data && (
          <LoadFailed what="the audit trail" error={entries.error} onRetry={() => void entries.refetch()} />
        )}
        {entries.data && entries.data.rows.length === 0 && (
          <EmptyState icon="audit" title="Nothing matches these filters" />
        )}

        {entries.data?.rows.map((entry) => (
          <article key={entry.id} className="border-b border-outline p-lg last:border-0">
            <div className="flex flex-wrap items-baseline gap-sm">
              <span className="font-mono text-body font-semibold">{entry.action}</span>
              <span className="text-body text-on-surface-muted">{personName(entry.user)}</span>
              <span className="ml-auto text-label text-on-surface-muted">{whenText(entry.created_at)}</span>
            </div>
            <p className="mt-xs text-label text-on-surface-muted">
              {entry.table_name ?? '—'}
              {entry.record_id && ` · ${entry.record_id}`}
              {entry.ip_address && ` · from ${entry.ip_address}`}
            </p>

            <button
              type="button"
              aria-expanded={expanded === entry.id}
              onClick={() => setExpanded(expanded === entry.id ? null : entry.id)}
              className="mt-sm text-label font-semibold text-primary"
            >
              {expanded === entry.id ? 'Hide what changed' : 'What changed'}
            </button>

            {expanded === entry.id && <AuditDetail id={entry.id} />}
          </article>
        ))}
      </Panel>

      <Pager pagination={entries.data?.pagination} onPage={(page) => update({ page })} />
    </>
  )
}

/**
 * Before and after, fetched from `GET /audit-logs/{id}`.
 *
 * Values are redacted on the way out: a defect elsewhere could one day put a
 * hash or a token into a recorded value, and this screen is where a person
 * would read it. Better to redact by key than to trust it never happens.
 */
function AuditDetail({ id }: { id: string }) {
  const entry = useQuery({ queryKey: governanceKeys.entry(id), queryFn: () => fetchAuditEntry(id) })

  if (entry.isPending) return <div className="mt-sm h-16 animate-pulse rounded-sm bg-surface-sunken" />
  if (entry.isError || !entry.data) {
    return <p className="mt-sm text-label text-on-surface-muted">That entry could not be loaded.</p>
  }

  const before = redact(entry.data.old_values)
  const after = redact(entry.data.new_values)

  return (
    <div className="mt-sm grid gap-lg sm:grid-cols-2">
      <ValueList title="Before" rows={before} />
      <ValueList title="After" rows={after} />
    </div>
  )
}

function ValueList({ title, rows }: { title: string; rows: Array<[string, string]> }) {
  return (
    <div>
      <h3 className="text-label font-medium text-on-surface-muted uppercase">{title}</h3>
      {rows.length === 0 ? (
        <p className="mt-xs text-label text-on-surface-muted">Nothing recorded.</p>
      ) : (
        <dl className="mt-xs rounded-sm border border-outline">
          {rows.map(([key, value]) => (
            <div key={key} className="flex gap-md border-b border-outline px-md py-xs text-label last:border-0">
              <dt className="text-on-surface-muted">{key}</dt>
              <dd className="ml-auto min-w-0 truncate font-mono">{value}</dd>
            </div>
          ))}
        </dl>
      )}
    </div>
  )
}

function AccessLog({ params, update }: TabProps) {
  const bulk = params.get('bulk') === '1'
  const page = Number(params.get('page') ?? 1)
  const entries = useQuery({
    queryKey: governanceKeys.access(bulk, page),
    queryFn: () => fetchAccessLog(bulk, page),
  })

  return (
    <>
      <div className="mb-lg flex flex-wrap items-center gap-md rounded-md border border-outline bg-surface p-md">
        <p className="text-body text-on-surface-muted">
          Who read whose personal data, and the reason they gave.
        </p>
        <button
          type="button"
          onClick={() => update({ bulk: bulk ? undefined : '1' })}
          className="h-[var(--size-control)] rounded-sm border border-outline px-md text-body"
        >
          {bulk ? 'Every access' : 'Only bulk access'}
        </button>
        <span className="ml-auto">
          <RangeLabel pagination={entries.data?.pagination} noun="accesses" />
        </span>
      </div>

      <Panel>
        {entries.isPending && <LoadingRows />}
        {entries.isError && !entries.data && (
          <LoadFailed what="the data access log" error={entries.error} onRetry={() => void entries.refetch()} />
        )}
        {entries.data && entries.data.rows.length === 0 && (
          <EmptyState
            icon="accessLog"
            title={bulk ? 'No bulk access recorded' : 'No personal data has been read'}
          />
        )}

        {entries.data && entries.data.rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-body">
              <thead>
                <tr className="border-b border-outline text-left text-label text-on-surface-muted uppercase">
                  <th scope="col" className="h-[var(--size-row-header)] px-lg font-medium">Who</th>
                  <th scope="col" className="px-lg font-medium">Data</th>
                  <th scope="col" className="px-lg font-medium">Subject</th>
                  <th scope="col" className="px-lg font-medium">Records</th>
                  <th scope="col" className="px-lg font-medium">Reason</th>
                  <th scope="col" className="px-lg font-medium">When</th>
                </tr>
              </thead>
              <tbody>
                {entries.data.rows.map((entry) => (
                  <tr key={entry.id} className="border-b border-outline align-top last:border-0">
                    <td className="px-lg py-md">{personName(entry.user)}</td>
                    <td className="px-lg py-md">
                      {humanise(entry.data_class)}
                      {entry.is_bulk && <StatusChip label="Bulk" tone="caution" />}
                    </td>
                    <td className="px-lg py-md font-mono text-label">{entry.subject_id ?? '—'}</td>
                    <td className="px-lg py-md font-mono">{entry.record_count ?? '—'}</td>
                    <td className="max-w-[22rem] px-lg py-md">{entry.reason ?? entry.purpose ?? '—'}</td>
                    <td className="px-lg py-md whitespace-nowrap">{whenText(entry.created_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Panel>

      <Pager pagination={entries.data?.pagination} onPage={(next) => update({ page: next })} />
    </>
  )
}

function Retention({ params, update }: TabProps) {
  const page = Number(params.get('page') ?? 1)
  const runs = useQuery({ queryKey: governanceKeys.retention(page), queryFn: () => fetchRetentionRuns(page) })

  return (
    <>
      <p className="mb-lg text-body text-on-surface-muted">
        What the scheduled purge matched, what it deleted, and where it refused.
      </p>

      <Panel>
        {runs.isPending && <LoadingRows rows={3} />}
        {runs.isError && !runs.data && (
          <LoadFailed what="retention runs" error={runs.error} onRetry={() => void runs.refetch()} />
        )}
        {runs.data && runs.data.rows.length === 0 && (
          <EmptyState icon="history" title="No retention run has been recorded" />
        )}

        {runs.data && runs.data.rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-body">
              <thead>
                <tr className="border-b border-outline text-left text-label text-on-surface-muted uppercase">
                  <th scope="col" className="h-[var(--size-row-header)] px-lg font-medium">Data</th>
                  <th scope="col" className="px-lg font-medium">Keep for</th>
                  <th scope="col" className="px-lg font-medium">Outcome</th>
                  <th scope="col" className="px-lg font-medium">Matched</th>
                  <th scope="col" className="px-lg font-medium">Purged</th>
                  <th scope="col" className="px-lg font-medium">When</th>
                </tr>
              </thead>
              <tbody>
                {runs.data.rows.map((run) => (
                  <tr key={run.id} className="border-b border-outline align-top last:border-0">
                    <td className="px-lg py-md">{humanise(run.data_class)}</td>
                    <td className="px-lg py-md font-mono">{run.retention_days} days</td>
                    <td className="px-lg py-md">
                      <StatusChip
                        label={humanise(run.outcome)}
                        tone={run.outcome === 'PURGED' ? 'positive' : run.refusal_reason ? 'caution' : 'neutral'}
                      />
                      {/* A refusal is the interesting row, not a footnote. */}
                      {run.refusal_reason && (
                        <p className="mt-xs text-label text-caution">{run.refusal_reason}</p>
                      )}
                    </td>
                    <td className="px-lg py-md font-mono">{run.records_matched}</td>
                    <td className="px-lg py-md font-mono">{run.records_purged}</td>
                    <td className="px-lg py-md whitespace-nowrap">{whenText(run.created_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Panel>

      <Pager pagination={runs.data?.pagination} onPage={(next) => update({ page: next })} />
    </>
  )
}
