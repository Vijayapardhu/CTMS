import { NavLink } from 'react-router-dom'
import { Icon } from '@/icons/Icon'
import { navigation } from '@/app/navigation'
import { AccessLevel, meets } from '@/auth/accessLevel'

type Props = {
  /** Null while the session is still resolving — nothing is offered yet. */
  level: AccessLevel | null
  collapsed: boolean
}

/**
 * C2 Sidebar.
 *
 * Sections the access level cannot reach are **absent, not disabled**. A
 * permanently greyed-out menu item teaches people the product is broken for
 * them; an item that was never there teaches them nothing at all, which is
 * correct.
 */
export function Sidebar({ level, collapsed }: Props) {
  const width = collapsed ? 'w-[var(--size-sidebar-collapsed)]' : 'w-[var(--size-sidebar)]'

  return (
    <nav
      aria-label="Sections"
      className={`${width} shrink-0 border-r border-outline bg-surface transition-[width] duration-[var(--motion-standard)]`}
    >
      <div className="flex h-[var(--size-topbar)] items-center gap-sm px-lg">
        <Icon name="buses" size="md" className="text-primary" />
        {!collapsed && <span className="text-title-md font-semibold">CTMS</span>}
      </div>

      <div className="flex flex-col gap-lg py-sm">
        {navigation.map((section, index) => {
          const items = section.items.filter((item) => meets(level, item.requires))
          if (items.length === 0) return null

          return (
            <div key={section.title ?? `group-${index}`}>
              {section.title && !collapsed && (
                <div className="px-lg pb-xs text-label font-medium tracking-wide text-on-surface-muted uppercase">
                  {section.title}
                </div>
              )}

              {items.map((item) => (
                <NavLink
                  key={item.path}
                  to={item.path}
                  end={item.path === '/'}
                  title={collapsed ? item.label : undefined}
                  className={({ isActive }) =>
                    [
                      'flex h-10 items-center gap-md px-lg text-body',
                      // Active is marked by weight AND a rule AND aria-current,
                      // never by colour alone.
                      isActive
                        ? 'border-l-2 border-primary bg-primary-container/40 pl-[calc(var(--spacing-lg)-2px)] font-semibold text-primary'
                        : 'text-on-surface hover:bg-surface-sunken',
                    ].join(' ')
                  }
                >
                  <Icon name={item.icon} size="sm" />
                  {!collapsed && <span className="truncate">{item.label}</span>}
                </NavLink>
              ))}
            </div>
          )
        })}
      </div>
    </nav>
  )
}
