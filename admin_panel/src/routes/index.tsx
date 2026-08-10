import type { ReactNode } from 'react'
import { Placeholder } from '@/components/Placeholder'
import type { AppIconName } from '@/icons/registry'
import { AccessLevel } from '@/auth/accessLevel'
import { DashboardScreen } from '@/features/dashboard/DashboardScreen'

type ScreenSpec = {
  path: string
  title: string
  icon: AppIconName
  slice: string
  /** The least privileged level that may reach this screen. */
  requires: AccessLevel
  /** Built. Absent means the placeholder still stands. */
  element?: ReactNode
}

/**
 * The frozen screen inventory, as routes.
 *
 * Every screen in 09-screen-specifications.md has a path from the start, so
 * routing, guards and navigation are settled before any of them has data in
 * it. The slice named is the one that fills the screen.
 */
export const screens: ScreenSpec[] = [
  {
    path: '/',
    title: 'Dashboard',
    icon: 'dashboard',
    slice: 'slice 2',
    requires: AccessLevel.VIEWER,
    element: <DashboardScreen />,
  },
  { path: '/live', title: 'Live Operations', icon: 'live', slice: 'slice 4', requires: AccessLevel.VIEWER },
  { path: '/trips', title: 'Trips', icon: 'trips', slice: 'slice 3', requires: AccessLevel.VIEWER },
  { path: '/trips/:id', title: 'Trip', icon: 'trips', slice: 'slice 3', requires: AccessLevel.VIEWER },
  { path: '/routes', title: 'Routes', icon: 'routes', slice: 'slice 5', requires: AccessLevel.VIEWER },
  { path: '/buses', title: 'Buses', icon: 'buses', slice: 'slice 5', requires: AccessLevel.VIEWER },
  { path: '/buses/:id', title: 'Bus', icon: 'buses', slice: 'slice 5', requires: AccessLevel.VIEWER },
  { path: '/drivers', title: 'Drivers', icon: 'drivers', slice: 'slice 7', requires: AccessLevel.VIEWER },
  { path: '/inspections', title: 'Inspections', icon: 'inspections', slice: 'slice 7', requires: AccessLevel.VIEWER },
  { path: '/maintenance', title: 'Maintenance', icon: 'maintenance', slice: 'slice 6', requires: AccessLevel.VIEWER },
  { path: '/incidents', title: 'Incidents', icon: 'incidents', slice: 'slice 6', requires: AccessLevel.VIEWER },
  { path: '/incidents/:id', title: 'Incident', icon: 'incidents', slice: 'slice 6', requires: AccessLevel.VIEWER },
  { path: '/students', title: 'Students', icon: 'students', slice: 'slice 7', requires: AccessLevel.VIEWER },
  { path: '/alerts', title: 'Alerts', icon: 'alerts', slice: 'slice 8', requires: AccessLevel.VIEWER },
  { path: '/announcements', title: 'Announcements', icon: 'announcements', slice: 'slice 8', requires: AccessLevel.VIEWER },
  { path: '/reports', title: 'Reports', icon: 'reports', slice: 'slice 8', requires: AccessLevel.VIEWER },
  { path: '/admin/audit', title: 'Audit', icon: 'audit', slice: 'slice 8', requires: AccessLevel.SUPER_ADMIN },
  { path: '/admin/accounts', title: 'Accounts', icon: 'accounts', slice: 'slice 8', requires: AccessLevel.SUPER_ADMIN },
]

export function screenElement(screen: ScreenSpec) {
  return screen.element ?? <Placeholder title={screen.title} icon={screen.icon} slice={screen.slice} />
}
