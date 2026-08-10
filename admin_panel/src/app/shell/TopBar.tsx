import { Icon } from '@/icons/Icon'
import { config } from '@/config/env'

type Props = {
  onToggleSidebar: () => void
  /** Absent until a session exists. */
  user?: { name: string; levelLabel: string } | null
  onSignOut?: () => void
}

/** C3 TopBar. Identity, environment and the way out. */
export function TopBar({ onToggleSidebar, user, onSignOut }: Props) {
  return (
    <header className="flex h-[var(--size-topbar)] shrink-0 items-center gap-md border-b border-outline bg-surface px-lg">
      <button
        type="button"
        onClick={onToggleSidebar}
        aria-label="Toggle navigation"
        className="grid size-9 place-items-center rounded-sm hover:bg-surface-sunken"
      >
        <Icon name="more" size="sm" />
      </button>

      <span className="text-title-md font-semibold">Transport Operations</span>

      {/* Off production only. A driver-facing build shows a person no
          plumbing, but a tester needs to know which backend they are on. */}
      {!config.isProduction && (
        <span className="rounded-sm bg-surface-sunken px-sm py-xs font-mono text-label text-on-surface-muted">
          {config.environment} · {config.apiHost}
        </span>
      )}

      <div className="ml-auto flex items-center gap-md">
        {user && (
          <div className="text-right leading-tight">
            <div className="text-body font-semibold">{user.name}</div>
            <div className="text-label text-on-surface-muted">{user.levelLabel}</div>
          </div>
        )}

        {onSignOut && (
          <button
            type="button"
            onClick={onSignOut}
            className="flex h-9 items-center gap-sm rounded-sm px-md text-body hover:bg-surface-sunken"
          >
            <Icon name="logout" size="sm" />
            Sign out
          </button>
        )}
      </div>
    </header>
  )
}
