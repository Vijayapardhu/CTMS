import { Placeholder } from '@/components/Placeholder'
import type { AppIconName } from '@/icons/registry'

type ScreenSpec = {
  path: string
  title: string
  icon: AppIconName
  slice: string
}

/**
 * The frozen screen inventory, as routes.
 *
 * Every screen in 09-screen-specifications.md has a path from the start, so
 * routing, guards and navigation are settled before any of them has data in
 * it. The slice named is the one that fills the screen.
 */
export const screens: ScreenSpec[] = [
  { path: '/', title: 'Dashboard', icon: 'dashboard', slice: 'slice 2' },
  { path: '/live', title: 'Live Operations', icon: 'live', slice: 'slice 4' },
  { path: '/trips', title: 'Trips', icon: 'trips', slice: 'slice 3' },
  { path: '/trips/:id', title: 'Trip', icon: 'trips', slice: 'slice 3' },
  { path: '/routes', title: 'Routes', icon: 'routes', slice: 'slice 5' },
  { path: '/buses', title: 'Buses', icon: 'buses', slice: 'slice 5' },
  { path: '/buses/:id', title: 'Bus', icon: 'buses', slice: 'slice 5' },
  { path: '/drivers', title: 'Drivers', icon: 'drivers', slice: 'slice 7' },
  { path: '/inspections', title: 'Inspections', icon: 'inspections', slice: 'slice 7' },
  { path: '/maintenance', title: 'Maintenance', icon: 'maintenance', slice: 'slice 6' },
  { path: '/incidents', title: 'Incidents', icon: 'incidents', slice: 'slice 6' },
  { path: '/incidents/:id', title: 'Incident', icon: 'incidents', slice: 'slice 6' },
  { path: '/students', title: 'Students', icon: 'students', slice: 'slice 7' },
  { path: '/alerts', title: 'Alerts', icon: 'alerts', slice: 'slice 8' },
  { path: '/announcements', title: 'Announcements', icon: 'announcements', slice: 'slice 8' },
  { path: '/reports', title: 'Reports', icon: 'reports', slice: 'slice 8' },
  { path: '/admin/audit', title: 'Audit', icon: 'audit', slice: 'slice 8' },
  { path: '/admin/accounts', title: 'Accounts', icon: 'accounts', slice: 'slice 8' },
]

export function screenElement(screen: ScreenSpec) {
  return <Placeholder title={screen.title} icon={screen.icon} slice={screen.slice} />
}
