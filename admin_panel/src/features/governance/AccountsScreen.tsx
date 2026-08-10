import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { StatusChip } from '@/components/StatusChip'
import { EmptyState, LoadFailed, LoadingRows, Pager, Panel, RangeLabel, RefreshButton, StaleBanner } from '@/components/Panel'
import { ActionButton, ConfirmDialog, OperationResult, useOperation } from '@/components/operations'
import { Can } from '@/auth/Can'
import { useSession } from '@/auth/SessionProvider'
import { Icon } from '@/icons/Icon'
import { request, requestPage, type Page } from '@/api/client'
import { AccessLevel, ACCESS_LEVEL_LABEL } from '@/auth/accessLevel'
import { subjectAccessExport, whenText } from './api'

type Account = {
  id: string
  email: string
  phone_number: string | null
  first_name: string
  last_name: string
  full_name?: string
  role: 'ADMIN' | 'DRIVER' | 'STUDENT'
  is_active: boolean
  last_login_at: string | null
  created_at: string
  profile?: { access_level?: AccessLevel | null; designation?: string; department?: string } | null
}

type AccountFilters = { role?: string; search?: string; active?: string; page?: number }

const accountKeys = {
  list: (filters: AccountFilters) => ['accounts', 'list', filters] as const,
}

const fetchAccounts = (filters: AccountFilters): Promise<Page<Account>> =>
  requestPage<Account>('/users', {
    query: {
      role: filters.role || undefined,
      search: filters.search || undefined,
      is_active: filters.active === '' || filters.active === undefined ? undefined : filters.active,
      page: filters.page,
      per_page: 20,
    },
  })

const setAccountActive = (id: string, isActive: boolean) =>
  request(`/users/${id}/status`, { method: 'PATCH', body: { is_active: isActive } })

const createAccount = (body: Record<string, unknown>) =>
  request('/users', { method: 'POST', body })

/**
 * A18 Accounts.
 *
 * Two things this screen deliberately does **not** offer, because the backend
 * does not:
 *
 * 1. **Deleting an account.** There is no `DELETE /users/{id}` and no `delete`
 *    ability to guard one. Accounts are deactivated, which keeps the history
 *    that references them intact.
 * 2. **Changing an existing account's access level.** It can be set when an
 *    administrator is created, but `UpdateUserRequest` does not accept
 *    `access_level` and no other endpoint takes it. A control here would be a
 *    button that silently did nothing. See `capability-registry.md`.
 *
 * The self-escalation guard is the server's: `UserPolicy::setActiveState`
 * refuses when actor and target are the same person. The panel disables the
 * control and says why, which is UX; the refusal is the boundary.
 */
export function AccountsScreen() {
  const [params, setParams] = useSearchParams()
  const queryClient = useQueryClient()
  const { user } = useSession()

  const filters: AccountFilters = {
    role: params.get('role') ?? '',
    search: params.get('search') ?? '',
    active: params.get('active') ?? '',
    page: Number(params.get('page') ?? 1),
  }

  const accounts = useQuery({ queryKey: accountKeys.list(filters), queryFn: () => fetchAccounts(filters) })

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
        title="Accounts"
        subtitle="Who can sign in to CTMS, and at what authority."
        actions={
          <div className="flex items-center gap-sm">
            <Can capability="account.create">
              <CreateAccountButton />
            </Can>
            <RefreshButton
              onClick={() => void queryClient.invalidateQueries({ queryKey: ['accounts'] })}
              busy={accounts.isFetching}
            />
          </div>
        }
      />

      <p className="mb-lg flex items-start gap-sm rounded-md border border-outline bg-surface p-md text-body">
        <Icon name="accounts" size="sm" className="mt-xs text-on-surface-muted" />
        <span>
          Accounts are deactivated, never deleted — the history that references them has to keep making sense.
          An access level is chosen when an administrator is created; there is no endpoint that changes it
          afterwards.
        </span>
      </p>

      <div className="mb-lg flex flex-wrap items-end gap-md rounded-md border border-outline bg-surface p-md">
        <label className="flex flex-col gap-xs">
          <span className="text-label font-medium text-on-surface-muted uppercase">Role</span>
          <select
            value={filters.role}
            onChange={(event) => update({ role: event.target.value })}
            className="h-[var(--size-control)] rounded-sm border border-outline bg-surface px-md text-body"
          >
            <option value="">Every role</option>
            <option value="ADMIN">Admin</option>
            <option value="DRIVER">Driver</option>
            <option value="STUDENT">Student</option>
          </select>
        </label>

        <label className="flex flex-col gap-xs">
          <span className="text-label font-medium text-on-surface-muted uppercase">Search</span>
          <input
            type="search"
            defaultValue={filters.search}
            placeholder="Name or email"
            onChange={(event) => update({ search: event.target.value })}
            className="h-[var(--size-control)] w-56 rounded-sm border border-outline bg-surface px-md text-body"
          />
        </label>

        <label className="flex flex-col gap-xs">
          <span className="text-label font-medium text-on-surface-muted uppercase">Status</span>
          <select
            value={filters.active}
            onChange={(event) => update({ active: event.target.value })}
            className="h-[var(--size-control)] rounded-sm border border-outline bg-surface px-md text-body"
          >
            <option value="">Active and inactive</option>
            <option value="1">Active only</option>
            <option value="0">Deactivated only</option>
          </select>
        </label>

        <span className="ml-auto">
          <RangeLabel pagination={accounts.data?.pagination} noun="accounts" />
        </span>
      </div>

      <Panel>
        {accounts.isError && accounts.data && (
          <StaleBanner error={accounts.error} onRetry={() => void accounts.refetch()} />
        )}
        {accounts.isPending && <LoadingRows />}
        {accounts.isError && !accounts.data && (
          <LoadFailed what="accounts" error={accounts.error} onRetry={() => void accounts.refetch()} />
        )}
        {accounts.data && accounts.data.rows.length === 0 && (
          <EmptyState icon="accounts" title="No accounts match these filters" />
        )}

        {accounts.data && accounts.data.rows.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-body">
              <thead>
                <tr className="border-b border-outline text-left text-label text-on-surface-muted uppercase">
                  <th scope="col" className="h-[var(--size-row-header)] px-lg font-medium">Name</th>
                  <th scope="col" className="px-lg font-medium">Email</th>
                  <th scope="col" className="px-lg font-medium">Role</th>
                  <th scope="col" className="px-lg font-medium">Authority</th>
                  <th scope="col" className="px-lg font-medium">Status</th>
                  <th scope="col" className="px-lg font-medium">Last signed in</th>
                  <th scope="col" className="px-lg font-medium" />
                </tr>
              </thead>
              <tbody>
                {accounts.data.rows.map((account) => (
                  <AccountRow key={account.id} account={account} isSelf={account.id === user?.id} />
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Panel>

      <Pager pagination={accounts.data?.pagination} onPage={(page) => update({ page })} />
    </>
  )
}

function AccountRow({ account, isSelf }: { account: Account; isSelf: boolean }) {
  const [dialog, setDialog] = useState<'status' | 'export' | null>(null)

  const setActive = useOperation({
    run: () => setAccountActive(account.id, !account.is_active),
    invalidate: [['accounts']],
  })
  const exportData = useOperation<string>({
    run: (reason) => subjectAccessExport(account.id, reason),
  })

  const name = account.full_name || `${account.first_name} ${account.last_name}`.trim()

  return (
    <tr className="border-b border-outline align-top last:border-0">
      <td className="px-lg py-md">
        {name}
        {isSelf && <span className="ml-sm text-label text-on-surface-muted">(you)</span>}
      </td>
      <td className="px-lg py-md">{account.email}</td>
      <td className="px-lg py-md">{account.role.charAt(0) + account.role.slice(1).toLowerCase()}</td>
      <td className="px-lg py-md">
        {account.profile?.access_level
          ? ACCESS_LEVEL_LABEL[account.profile.access_level as AccessLevel] ?? account.profile.access_level
          : '—'}
      </td>
      <td className="px-lg py-md">
        <StatusChip
          label={account.is_active ? 'Active' : 'Deactivated'}
          tone={account.is_active ? 'positive' : 'neutral'}
          icon={account.is_active ? 'success' : 'blocked'}
        />
        {(setActive.failure || setActive.success || exportData.failure || exportData.success) && (
          <div className="mt-xs max-w-sm">
            <OperationResult operation={setActive.failure || setActive.success ? setActive : exportData} />
          </div>
        )}
      </td>
      <td className="px-lg py-md whitespace-nowrap">{whenText(account.last_login_at)}</td>
      <td className="px-lg py-md text-right">
        <div className="flex justify-end gap-sm">
          <Can capability="personalData.export">
            <ActionButton label="Export their data" icon="download" onClick={() => setDialog('export')} />
          </Can>
          <Can capability="account.setActive">
            <ActionButton
              label={account.is_active ? 'Deactivate' : 'Activate'}
              tone={account.is_active ? 'destructive' : 'neutral'}
              disabled={isSelf}
              // The server refuses this outright; the panel explains it.
              title={isSelf ? 'You cannot change your own account’s status.' : undefined}
              onClick={() => setDialog('status')}
            />
          </Can>
        </div>
      </td>

      <td className="hidden">
        <ConfirmDialog
          open={dialog === 'status'}
          title={account.is_active ? `Deactivate ${name}?` : `Activate ${name}?`}
          body={
            account.is_active
              ? 'They are signed out everywhere immediately and cannot sign in again. Nothing they did is removed.'
              : 'They will be able to sign in again with their existing password.'
          }
          confirmLabel={account.is_active ? 'Deactivate' : 'Activate'}
          tone={account.is_active ? 'destructive' : 'primary'}
          operation={setActive}
          onClose={() => {
            setDialog(null)
            setActive.reset()
          }}
          onConfirm={() => void setActive.run().then((ok) => ok && setDialog(null))}
        />

        <ConfirmDialog
          open={dialog === 'export'}
          title={`Export everything CTMS holds about ${name}?`}
          // BR-502 — the export is itself an access, and a notable one.
          body="This produces a copy of their entire record, and is written to the data access log with your name and the reason you give here."
          confirmLabel="Generate export"
          tone="primary"
          reason={{
            label: 'Why is this being requested?',
            field: 'reason',
            minLength: 10,
            hint: 'At least 10 characters. This is recorded.',
          }}
          operation={exportData}
          onClose={() => {
            setDialog(null)
            exportData.reset()
          }}
          onConfirm={(reason) => void exportData.run(reason).then((ok) => ok && setDialog(null))}
        />
      </td>
    </tr>
  )
}

/**
 * Creating an account.
 *
 * `POST /users` is `AuthController::register` behind `access:SUPER_ADMIN`, and
 * it takes the profile fields for whichever role is chosen. The access level is
 * offered here **only** for an administrator, because that is the only role the
 * request accepts it for — and only here, because nothing changes it later.
 */
function CreateAccountButton() {
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({
    first_name: '',
    last_name: '',
    email: '',
    phone_number: '',
    password: '',
    role: 'ADMIN' as 'ADMIN' | 'DRIVER' | 'STUDENT',
    designation: '',
    department: '',
    access_level: AccessLevel.VIEWER as AccessLevel,
  })

  const create = useOperation<void>({
    run: () =>
      createAccount({
        first_name: form.first_name.trim(),
        last_name: form.last_name.trim(),
        email: form.email.trim(),
        phone_number: form.phone_number.trim(),
        password: form.password,
        password_confirmation: form.password,
        role: form.role,
        ...(form.role === 'ADMIN'
          ? {
              designation: form.designation.trim(),
              department: form.department.trim(),
              access_level: form.access_level,
            }
          : {}),
      }),
    invalidate: [['accounts']],
  })

  const field = (key: keyof typeof form) => ({
    value: form[key] as string,
    onChange: (event: { target: { value: string } }) => setForm({ ...form, [key]: event.target.value }),
  })

  return (
    <>
      <ActionButton label="Add an account" tone="primary" icon="assign" onClick={() => setOpen(true)} />

      {open && (
        <div className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-black/40 p-lg">
          <div
            role="dialog"
            aria-modal="true"
            aria-label="Add an account"
            className="w-full max-w-xl rounded-md border border-outline bg-surface p-xl"
          >
            <h2 className="text-title-lg font-semibold">Add an account</h2>

            <div className="mt-lg grid gap-md sm:grid-cols-2">
              <Text label="First name" error={create.fieldError('first_name')} {...field('first_name')} />
              <Text label="Last name" error={create.fieldError('last_name')} {...field('last_name')} />
              <Text label="Email" error={create.fieldError('email')} {...field('email')} />
              <Text label="Phone" error={create.fieldError('phone_number')} {...field('phone_number')} />
            </div>

            {/*
              The one field the panel never fills in for somebody: a password
              is typed by the person setting the account up, and is not
              generated, mailed or displayed anywhere afterwards.
            */}
            <label className="mt-lg block">
              <span className="text-label font-medium text-on-surface-muted uppercase">Temporary password</span>
              <input
                type="password"
                value={form.password}
                onChange={(event) => setForm({ ...form, password: event.target.value })}
                className="mt-xs h-[var(--size-control)] w-full rounded-sm border border-outline bg-surface px-md text-body"
              />
              {create.fieldError('password') && (
                <span className="mt-xs block text-label text-critical">{create.fieldError('password')}</span>
              )}
            </label>

            <label className="mt-lg block">
              <span className="text-label font-medium text-on-surface-muted uppercase">Role</span>
              <select
                value={form.role}
                onChange={(event) => setForm({ ...form, role: event.target.value as typeof form.role })}
                className="mt-xs h-[var(--size-control)] w-full rounded-sm border border-outline bg-surface px-md text-body"
              >
                <option value="ADMIN">Admin — transport office</option>
                <option value="DRIVER">Driver</option>
                <option value="STUDENT">Student</option>
              </select>
            </label>

            {form.role === 'ADMIN' && (
              <div className="mt-lg grid gap-md sm:grid-cols-2">
                <Text label="Designation" error={create.fieldError('designation')} {...field('designation')} />
                <Text label="Department" error={create.fieldError('department')} {...field('department')} />
                <label className="block sm:col-span-2">
                  <span className="text-label font-medium text-on-surface-muted uppercase">Authority</span>
                  <select
                    value={form.access_level}
                    onChange={(event) =>
                      setForm({ ...form, access_level: event.target.value as AccessLevel })
                    }
                    className="mt-xs h-[var(--size-control)] w-full rounded-sm border border-outline bg-surface px-md text-body"
                  >
                    {Object.values(AccessLevel).map((level) => (
                      <option key={level} value={level}>
                        {ACCESS_LEVEL_LABEL[level]}
                      </option>
                    ))}
                  </select>
                  <span className="mt-xs block text-label text-on-surface-muted">
                    Chosen once. No endpoint changes it afterwards, so choose deliberately.
                  </span>
                </label>
              </div>
            )}

            {form.role !== 'ADMIN' && (
              <p className="mt-lg text-label text-on-surface-muted">
                A {form.role.toLowerCase()} account needs profile details this form does not collect —
                CTMS creates those through the {form.role === 'DRIVER' ? 'driver' : 'student'} records.
              </p>
            )}

            <div className="mt-lg">
              <OperationResult operation={create} />
            </div>

            <div className="mt-lg flex justify-end gap-sm">
              <ActionButton
                label="Cancel"
                onClick={() => {
                  setOpen(false)
                  create.reset()
                }}
              />
              <ActionButton
                label="Create account"
                tone="primary"
                busy={create.isPending}
                disabled={!form.email || !form.password || !form.first_name}
                onClick={() => void create.run().then((ok) => ok && setOpen(false))}
              />
            </div>
          </div>
        </div>
      )}
    </>
  )
}

function Text({
  label,
  value,
  onChange,
  error,
}: {
  label: string
  value: string
  onChange: (event: { target: { value: string } }) => void
  error?: string
}) {
  return (
    <label className="block">
      <span className="text-label font-medium text-on-surface-muted uppercase">{label}</span>
      <input
        type="text"
        value={value}
        onChange={onChange}
        className="mt-xs h-[var(--size-control)] w-full rounded-sm border border-outline bg-surface px-md text-body"
      />
      {error && <span className="mt-xs block text-label text-critical">{error}</span>}
    </label>
  )
}
