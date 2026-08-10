import { request, requestPage, type Page } from '@/api/client'

/**
 * Governance — the audit trail, the data-access log, retention runs, and a
 * subject access export.
 *
 * All of it is SUPER_ADMIN and all of it is **read-only**. The backend has no
 * write endpoint for `audit_logs` at all, deliberately: a trail is evidence
 * only for as long as nobody can reach in and adjust it. The panel therefore
 * offers no edit and no delete, and there is nothing to offer.
 */

export type AuditEntry = {
  id: string
  action: string
  table_name: string | null
  record_id: string | null
  old_values: Record<string, unknown> | string | null
  new_values: Record<string, unknown> | string | null
  ip_address: string | null
  user_agent: string | null
  created_at: string
  user?: { id: string; full_name?: string; first_name?: string; last_name?: string; email?: string } | null
}

export type DataAccessEntry = {
  id: string
  subject_id: string | null
  subject_type: string | null
  purpose: string | null
  data_class: string | null
  is_bulk: boolean
  record_count: number | null
  reason: string | null
  ip_address: string | null
  created_at: string
  user?: { id: string; full_name?: string; first_name?: string; last_name?: string } | null
}

export type RetentionRun = {
  id: string
  data_class: string
  retention_days: number
  outcome: string
  records_matched: number
  records_purged: number
  refusal_reason: string | null
  cutoff_at: string | null
  created_at: string
}

export type AuditFilters = {
  action?: string
  table_name?: string
  user_id?: string
  from?: string
  to?: string
  page?: number
}

export const governanceKeys = {
  audit: (filters: AuditFilters) => ['audit', 'list', filters] as const,
  entry: (id: string) => ['audit', 'entry', id] as const,
  access: (bulk: boolean, page: number) => ['audit', 'access', bulk, page] as const,
  retention: (page: number) => ['audit', 'retention', page] as const,
}

export const fetchAuditLog = (filters: AuditFilters): Promise<Page<AuditEntry>> =>
  requestPage<AuditEntry>('/audit-logs', {
    query: {
      action: filters.action || undefined,
      table_name: filters.table_name || undefined,
      user_id: filters.user_id || undefined,
      from: filters.from || undefined,
      to: filters.to || undefined,
      page: filters.page,
      per_page: 20,
    },
  })

export const fetchAuditEntry = async (id: string): Promise<AuditEntry> =>
  (await request<AuditEntry>(`/audit-logs/${id}`)).data

export const fetchAccessLog = (bulk: boolean, page = 1): Promise<Page<DataAccessEntry>> =>
  requestPage<DataAccessEntry>('/data-access-logs', { query: { bulk: bulk ? 1 : undefined, page, per_page: 20 } })

export const fetchRetentionRuns = (page = 1): Promise<Page<RetentionRun>> =>
  requestPage<RetentionRun>('/retention-runs', { query: { page, per_page: 20 } })

/**
 * BR-506 — everything the system holds about one person.
 *
 * A POST rather than a GET, and not because of REST fashion: it produces a
 * copy of somebody's entire record and writes a high-visibility access entry.
 * A GET would end up in a browser history, a proxy log and a bookmark.
 */
export const subjectAccessExport = (userId: string, reason: string) =>
  request(`/users/${userId}/subject-access-export`, { method: 'POST', body: { reason } })

// ── presentation ───────────────────────────────────────────────────────────

export function personName(
  person: { full_name?: string; first_name?: string; last_name?: string } | null | undefined,
): string {
  if (!person) return 'The system'

  return person.full_name || [person.first_name, person.last_name].filter(Boolean).join(' ') || 'Unknown'
}

export function humanise(value: string | null | undefined): string {
  if (!value) return '—'
  const words = value.replace(/_/g, ' ').toLowerCase()

  return words.charAt(0).toUpperCase() + words.slice(1)
}

export function whenText(value: string | null | undefined): string {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value

  return date.toLocaleString(undefined, {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

/**
 * Anything that must never be rendered, whatever a row happens to contain.
 *
 * The audit trail stores before-and-after values, and a defect elsewhere could
 * one day put a hash or a token in one. This screen is where such a value
 * would be read by a person, so it is redacted here rather than trusted not to
 * appear.
 */
const SECRET_KEYS = /(password|token|secret|refresh|authorization|api[_-]?key|private)/i

export function redact(values: Record<string, unknown> | string | null): Array<[string, string]> {
  if (values === null) return []

  let parsed: unknown = values
  if (typeof values === 'string') {
    try {
      parsed = JSON.parse(values)
    } catch {
      return [['value', values]]
    }
  }

  if (typeof parsed !== 'object' || parsed === null) return [['value', String(parsed)]]

  return Object.entries(parsed as Record<string, unknown>).map(([key, value]) => [
    key,
    SECRET_KEYS.test(key) ? '[redacted]' : value === null ? '—' : typeof value === 'object' ? JSON.stringify(value) : String(value),
  ])
}
