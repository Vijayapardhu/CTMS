import {
  Alert01Icon,
  Alert02Icon,
  AlertCircleIcon,
  ArrowDown01Icon,
  ArrowLeft01Icon,
  ArrowRight01Icon,
  Bus01Icon,
  Calendar03Icon,
  CancelCircleIcon,
  Cancel01Icon,
  ChartBarLineIcon,
  ChartLineData01Icon,
  CheckListIcon,
  CheckmarkCircle02Icon,
  Clock01Icon,
  DashboardCircleIcon,
  DashboardSpeed01Icon,
  Download01Icon,
  EyeIcon,
  File01Icon,
  FilterIcon,
  Gps01Icon,
  Image01Icon,
  Location01Icon,
  Logout01Icon,
  MapsLocation01Icon,
  Megaphone01Icon,
  MoreVerticalIcon,
  Navigation03Icon,
  NoInternetIcon,
  Notification03Icon,
  RefreshIcon,
  Route01Icon,
  Search01Icon,
  SecurityCheckIcon,
  SentIcon,
  Settings01Icon,
  ShieldEnergyIcon,
  Timer02Icon,
  UserAdd01Icon,
  UserGroupIcon,
  UserIcon,
  Wrench01Icon,
} from '@hugeicons/core-free-icons'

/**
 * The one place a Hugeicons symbol is named.
 *
 * No component imports from `@hugeicons/core-free-icons` directly — the rule
 * the driver app follows, for the same reason: when a symbol turns out not to
 * exist, exactly one file changes. Two of the names the specification proposed
 * did not exist in the package (`CloudOffIcon`, `Dashboard01Icon`); they were
 * caught here rather than at runtime, and `icons.test.ts` asserts every entry
 * still resolves.
 */
export const AppIcon = {
  // Navigation
  dashboard: DashboardCircleIcon,
  live: Navigation03Icon,
  trips: Route01Icon,
  buses: Bus01Icon,
  drivers: UserIcon,
  inspections: CheckListIcon,
  maintenance: Wrench01Icon,
  incidents: Alert02Icon,
  students: UserGroupIcon,
  alerts: Notification03Icon,
  announcements: Megaphone01Icon,
  reports: ChartLineData01Icon,
  audit: SecurityCheckIcon,
  accounts: Settings01Icon,
  routes: MapsLocation01Icon,

  // Chrome
  back: ArrowLeft01Icon,
  forward: ArrowRight01Icon,
  close: Cancel01Icon,
  expand: ArrowDown01Icon,
  more: MoreVerticalIcon,
  search: Search01Icon,
  filter: FilterIcon,
  refresh: RefreshIcon,
  download: Download01Icon,
  logout: Logout01Icon,

  // Operational state
  gps: Gps01Icon,
  offline: NoInternetIcon,
  stop: Location01Icon,
  eta: Timer02Icon,
  schedule: Calendar03Icon,
  odometer: DashboardSpeed01Icon,
  capacity: ChartBarLineIcon,
  evidence: Image01Icon,
  document: File01Icon,
  history: Clock01Icon,
  assign: UserAdd01Icon,
  send: SentIcon,
  accessLog: EyeIcon,

  // Status. Never used without a word beside them.
  success: CheckmarkCircle02Icon,
  warning: Alert01Icon,
  error: AlertCircleIcon,
  blocked: CancelCircleIcon,
  sos: ShieldEnergyIcon,
} as const

export type AppIconName = keyof typeof AppIcon
