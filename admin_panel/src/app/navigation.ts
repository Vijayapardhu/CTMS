import type { AppIconName } from '@/icons/registry'
import type { CapabilityId } from '@/auth/capabilities'

export type NavItem = {
  label: string
  path: string
  icon: AppIconName
  /** The capability that makes this screen reachable. */
  capability: CapabilityId
}

export type NavSection = {
  /** Absent on the first group, which needs no heading. */
  title?: string
  items: NavItem[]
}

/**
 * The information architecture, gated by capability rather than by level.
 *
 * One source of truth: the sidebar, the router and every action ask the same
 * registry. Three independent permission systems is how a screen ends up
 * reachable with every control on it forbidden.
 *
 * Items the operator cannot reach are **absent**, not disabled.
 */
export const navigation: NavSection[] = [
  {
    items: [{ label: 'Dashboard', path: '/', icon: 'dashboard', capability: 'dashboard.read' }],
  },
  {
    title: 'Operations',
    items: [
      { label: 'Live Operations', path: '/live', icon: 'live', capability: 'live.read' },
      { label: 'Trips', path: '/trips', icon: 'trips', capability: 'trips.read' },
      { label: 'Routes', path: '/routes', icon: 'routes', capability: 'routes.read' },
    ],
  },
  {
    title: 'Fleet',
    items: [
      { label: 'Buses', path: '/buses', icon: 'buses', capability: 'fleet.read' },
      { label: 'Drivers', path: '/drivers', icon: 'drivers', capability: 'drivers.read' },
      { label: 'Inspections', path: '/inspections', icon: 'inspections', capability: 'bus.readiness.read' },
      { label: 'Maintenance', path: '/maintenance', icon: 'maintenance', capability: 'maintenance.read' },
    ],
  },
  {
    title: 'Safety',
    items: [
      { label: 'Incidents', path: '/incidents', icon: 'incidents', capability: 'incidents.read' },
      { label: 'Recovery', path: '/replacements', icon: 'swap', capability: 'replacements.read' },
    ],
  },
  {
    title: 'People',
    items: [{ label: 'Students', path: '/students', icon: 'students', capability: 'students.read' }],
  },
  {
    title: 'Communication',
    items: [
      { label: 'Alerts', path: '/alerts', icon: 'alerts', capability: 'notifications.read' },
      { label: 'Announcements', path: '/announcements', icon: 'announcements', capability: 'announcements.read' },
    ],
  },
  {
    items: [{ label: 'Reports', path: '/reports', icon: 'reports', capability: 'reports.read' }],
  },
  {
    title: 'Administration',
    items: [
      { label: 'Audit', path: '/admin/audit', icon: 'audit', capability: 'audit.read' },
      { label: 'Data Access', path: '/admin/access-log', icon: 'accessLog', capability: 'audit.accessLog.read' },
      { label: 'Accounts', path: '/admin/accounts', icon: 'accounts', capability: 'account.create' },
    ],
  },
]

/** Every navigable path, for the route table and its guards. */
export const navItems: NavItem[] = navigation.flatMap((section) => section.items)
