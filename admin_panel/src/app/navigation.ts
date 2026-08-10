import type { AppIconName } from '@/icons/registry'
import { AccessLevel } from '@/auth/accessLevel'

export type NavItem = {
  label: string
  path: string
  icon: AppIconName
  /** The least privileged level that may reach this screen. */
  requires: AccessLevel
}

export type NavSection = {
  /** Absent on the first group, which needs no heading. */
  title?: string
  items: NavItem[]
}

/**
 * The information architecture from 02-screen-api-matrix.md, verified against
 * the router before it was written down.
 *
 * `requires` is the *navigation* gate. It is not a security control — the
 * server decides — but a sidebar that offers a screen returning 403 teaches
 * people the product is broken for them.
 */
export const navigation: NavSection[] = [
  {
    items: [{ label: 'Dashboard', path: '/', icon: 'dashboard', requires: AccessLevel.VIEWER }],
  },
  {
    title: 'Operations',
    items: [
      { label: 'Live Operations', path: '/live', icon: 'live', requires: AccessLevel.VIEWER },
      { label: 'Trips', path: '/trips', icon: 'trips', requires: AccessLevel.VIEWER },
      { label: 'Routes', path: '/routes', icon: 'routes', requires: AccessLevel.VIEWER },
    ],
  },
  {
    title: 'Fleet',
    items: [
      { label: 'Buses', path: '/buses', icon: 'buses', requires: AccessLevel.VIEWER },
      { label: 'Drivers', path: '/drivers', icon: 'drivers', requires: AccessLevel.VIEWER },
      { label: 'Inspections', path: '/inspections', icon: 'inspections', requires: AccessLevel.VIEWER },
      { label: 'Maintenance', path: '/maintenance', icon: 'maintenance', requires: AccessLevel.VIEWER },
    ],
  },
  {
    title: 'Safety',
    items: [{ label: 'Incidents', path: '/incidents', icon: 'incidents', requires: AccessLevel.VIEWER }],
  },
  {
    title: 'People',
    items: [{ label: 'Students', path: '/students', icon: 'students', requires: AccessLevel.VIEWER }],
  },
  {
    title: 'Communication',
    items: [
      { label: 'Alerts', path: '/alerts', icon: 'alerts', requires: AccessLevel.VIEWER },
      { label: 'Announcements', path: '/announcements', icon: 'announcements', requires: AccessLevel.VIEWER },
    ],
  },
  {
    items: [{ label: 'Reports', path: '/reports', icon: 'reports', requires: AccessLevel.VIEWER }],
  },
  {
    title: 'Administration',
    items: [
      { label: 'Audit', path: '/admin/audit', icon: 'audit', requires: AccessLevel.SUPER_ADMIN },
      { label: 'Accounts', path: '/admin/accounts', icon: 'accounts', requires: AccessLevel.SUPER_ADMIN },
    ],
  },
]

/** Every navigable path, for the route table and its guards. */
export const navItems: NavItem[] = navigation.flatMap((section) => section.items)
