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
import { AttendanceScreen } from '@/features/attendance/AttendanceScreen'
import { AnnouncementsScreen } from '@/features/comms/AnnouncementsScreen'
import { AlertsScreen } from '@/features/comms/AlertsScreen'
import { ReportsScreen } from '@/features/reports/ReportsScreen'
import { GovernanceScreen } from '@/features/governance/GovernanceScreen'
import { AccountsScreen } from '@/features/governance/AccountsScreen'
import { DriversScreen } from '@/features/people/DriversScreen'
import { StudentsScreen } from '@/features/people/StudentsScreen'
import { RoutesScreen } from '@/features/routes/RoutesScreen'
import { InspectionsScreen } from '@/features/fleet/InspectionsScreen'

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
  {
    path: '/routes',
    title: 'Routes',
    icon: 'routes',
    slice: 'phase 13',
    capability: 'routes.read',
    element: <RoutesScreen />,
  },
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
  {
    path: '/drivers',
    title: 'Drivers',
    icon: 'drivers',
    slice: 'phase 13',
    capability: 'drivers.read',
    element: <DriversScreen />,
  },
  {
    path: '/inspections',
    title: 'Inspections',
    icon: 'inspections',
    slice: 'phase 13',
    capability: 'bus.readiness.read',
    element: <InspectionsScreen />,
  },
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
  {
    path: '/attendance',
    title: 'Attendance',
    icon: 'capacity',
    slice: 'phase 9',
    capability: 'attendance.read',
    element: <AttendanceScreen />,
  },
  {
    path: '/students',
    title: 'Students',
    icon: 'students',
    slice: 'phase 13',
    capability: 'students.read',
    element: <StudentsScreen />,
  },
  {
    path: '/alerts',
    title: 'Alerts',
    icon: 'alerts',
    slice: 'phase 10',
    capability: 'notifications.read',
    element: <AlertsScreen />,
  },
  {
    path: '/announcements',
    title: 'Announcements',
    icon: 'announcements',
    slice: 'phase 10',
    capability: 'announcements.read',
    element: <AnnouncementsScreen />,
  },
  {
    path: '/reports',
    title: 'Reports',
    icon: 'reports',
    slice: 'phase 11',
    capability: 'reports.read',
    element: <ReportsScreen />,
  },
  {
    path: '/reports/:kind',
    title: 'Report',
    icon: 'reports',
    slice: 'phase 11',
    capability: 'reports.read',
    element: <ReportsScreen />,
  },
  {
    path: '/admin/audit',
    title: 'Audit',
    icon: 'audit',
    slice: 'phase 12',
    capability: 'audit.read',
    element: <GovernanceScreen />,
  },
  {
    path: '/admin/access-log',
    title: 'Data Access',
    icon: 'accessLog',
    slice: 'phase 12',
    capability: 'audit.accessLog.read',
    element: <GovernanceScreen />,
  },
  {
    path: '/admin/accounts',
    title: 'Accounts',
    icon: 'accounts',
    slice: 'phase 13',
    capability: 'account.create',
    element: <AccountsScreen />,
  },
]

export function screenElement(screen: ScreenSpec) {
  return screen.element ?? <Placeholder title={screen.title} icon={screen.icon} slice={screen.slice} />
}
