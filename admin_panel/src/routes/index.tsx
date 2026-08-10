import type { ReactNode } from 'react'
import { Placeholder } from '@/components/Placeholder'
import type { AppIconName } from '@/icons/registry'
import type { CapabilityId } from '@/auth/capabilities'
import { DashboardScreen } from '@/features/dashboard/DashboardScreen'
import { TripsScreen } from '@/features/trips/TripsScreen'
import { TripDetailScreen } from '@/features/trips/TripDetailScreen'
import { LiveOperationsScreen } from '@/features/live/LiveOperationsScreen'
import { FleetScreen } from '@/features/fleet/FleetScreen'
import { BusDetailScreen } from '@/features/fleet/BusDetailScreen'
import { IncidentsScreen } from '@/features/incidents/IncidentsScreen'
import { IncidentDetailScreen } from '@/features/incidents/IncidentDetailScreen'
import { MaintenanceScreen } from '@/features/maintenance/MaintenanceScreen'
import { MaintenanceDetailScreen } from '@/features/maintenance/MaintenanceDetailScreen'
import { RecoveryScreen } from '@/features/recovery/RecoveryScreen'

type ScreenSpec = {
  path: string
  title: string
  icon: AppIconName
  slice: string
  /** The capability that makes this screen reachable. */
  capability: CapabilityId
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
    capability: 'dashboard.read',
    element: <DashboardScreen />,
  },
  {
    path: '/live',
    title: 'Live Operations',
    icon: 'live',
    slice: 'slice 4',
    capability: 'live.read',
    element: <LiveOperationsScreen />,
  },
  {
    path: '/trips',
    title: 'Trips',
    icon: 'trips',
    slice: 'slice 3',
    capability: 'trips.read',
    element: <TripsScreen />,
  },
  {
    path: '/trips/:id',
    title: 'Trip',
    icon: 'trips',
    slice: 'slice 3',
    capability: 'trip.read',
    element: <TripDetailScreen />,
  },
  { path: '/routes', title: 'Routes', icon: 'routes', slice: 'slice 5', capability: 'routes.read' },
  {
    path: '/buses',
    title: 'Buses',
    icon: 'buses',
    slice: 'slice 5',
    capability: 'fleet.read',
    element: <FleetScreen />,
  },
  {
    path: '/buses/:id',
    title: 'Bus',
    icon: 'buses',
    slice: 'slice 5',
    capability: 'bus.read',
    element: <BusDetailScreen />,
  },
  { path: '/drivers', title: 'Drivers', icon: 'drivers', slice: 'slice 7', capability: 'drivers.read' },
  { path: '/inspections', title: 'Inspections', icon: 'inspections', slice: 'slice 7', capability: 'bus.readiness.read' },
  {
    path: '/maintenance',
    title: 'Maintenance',
    icon: 'maintenance',
    slice: 'phase 7',
    capability: 'maintenance.read',
    element: <MaintenanceScreen />,
  },
  {
    path: '/maintenance/:id',
    title: 'Maintenance ticket',
    icon: 'maintenance',
    slice: 'phase 7',
    capability: 'maintenance.ticket.read',
    element: <MaintenanceDetailScreen />,
  },
  {
    path: '/incidents',
    title: 'Incidents',
    icon: 'incidents',
    slice: 'phase 6',
    capability: 'incidents.read',
    element: <IncidentsScreen />,
  },
  {
    path: '/incidents/:id',
    title: 'Incident',
    icon: 'incidents',
    slice: 'phase 6',
    capability: 'incident.read',
    element: <IncidentDetailScreen />,
  },
  {
    path: '/replacements',
    title: 'Recovery',
    icon: 'swap',
    slice: 'phase 8',
    capability: 'replacements.read',
    element: <RecoveryScreen />,
  },
  { path: '/students', title: 'Students', icon: 'students', slice: 'slice 7', capability: 'students.read' },
  { path: '/alerts', title: 'Alerts', icon: 'alerts', slice: 'slice 8', capability: 'notifications.read' },
  { path: '/announcements', title: 'Announcements', icon: 'announcements', slice: 'slice 8', capability: 'announcements.read' },
  { path: '/reports', title: 'Reports', icon: 'reports', slice: 'slice 8', capability: 'reports.read' },
  { path: '/admin/audit', title: 'Audit', icon: 'audit', slice: 'slice 8', capability: 'audit.read' },
  { path: '/admin/access-log', title: 'Data Access', icon: 'accessLog', slice: 'phase 15', capability: 'audit.accessLog.read' },
  { path: '/admin/accounts', title: 'Accounts', icon: 'accounts', slice: 'slice 8', capability: 'account.create' },
]

export function screenElement(screen: ScreenSpec) {
  return screen.element ?? <Placeholder title={screen.title} icon={screen.icon} slice={screen.slice} />
}
