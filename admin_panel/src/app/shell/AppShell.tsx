import { useEffect, useState, type ReactNode } from 'react'
import { Sidebar } from './Sidebar'
import { TopBar } from './TopBar'

type Props = {
  user?: { name: string; levelLabel: string } | null
  onSignOut?: () => void
  children: ReactNode
}

/**
 * C1 AppShell.
 *
 * Sidebar, top bar, content. The layout is a two-column grid rather than a
 * fixed-position sidebar so that content can never slide underneath it, and
 * the offline banner — when it arrives in a later slice — pushes content down
 * instead of covering the control somebody is reaching for.
 */
export function AppShell({ user, onSignOut, children }: Props) {
  // Below 1280 the sidebar collapses to icons. Desktop-first: this is a
  // laptop application, and shrinking it into a phone layout would make the
  // tables it exists for unusable.
  const [collapsed, setCollapsed] = useState(() =>
    typeof window !== 'undefined' ? window.innerWidth < 1280 : false,
  )
  const [pinned, setPinned] = useState(false)

  useEffect(() => {
    if (pinned) return

    const onResize = () => setCollapsed(window.innerWidth < 1280)
    window.addEventListener('resize', onResize)

    return () => window.removeEventListener('resize', onResize)
  }, [pinned])

  return (
    <div className="flex h-full">
      <Sidebar collapsed={collapsed} />

      <div className="flex min-w-0 flex-1 flex-col">
        <TopBar
          onToggleSidebar={() => {
            setPinned(true)
            setCollapsed((value) => !value)
          }}
          user={user}
          onSignOut={onSignOut}
        />

        <main id="content" className="min-w-0 flex-1 overflow-auto p-xl">
          <div className="mx-auto max-w-[1600px]">{children}</div>
        </main>
      </div>
    </div>
  )
}
