import { request, requestPage, type Page } from '@/api/client'

/**
 * Announcements, this admin's own alerts, and whether the system is reaching
 * handsets at all.
 *
 * The last two are deliberately separate things (G1-4): "nothing in my inbox"
 * and "nothing is being delivered to anybody" look identical on a screen that
 * merges them, and mean opposite things.
 */

export type AnnouncementAudience = 'ALL' | 'STUDENTS' | 'DRIVERS' | 'ADMINS'
export type AnnouncementPriority = 'LOW' | 'MEDIUM' | 'HIGH'

export type Announcement = {
  id: string
  title: string
  content: string
  target_audience: AnnouncementAudience
  priority: AnnouncementPriority
  published_at: string | null
  expires_at: string | null
  is_active: boolean
  created_at: string
  created_by?: { id: string; full_name?: string; first_name?: string; last_name?: string } | null
}

export type AppNotification = {
  id: string
  event_key: string
  category: string
  priority: string
  title: string
  body: string | null
  read_at: string | null
  created_at: string
}

export type DeliveryStatus = 'QUEUED' | 'SENT' | 'DELIVERED' | 'RETRYING' | 'PERMANENTLY_FAILED' | 'SUPPRESSED'

export type Delivery = {
  id: string
  notification_id: string
  channel: string
  status: DeliveryStatus
  attempts: number
  first_attempted_at: string | null
  last_attempted_at: string | null
  delivered_at: string | null
  reason: string | null
  created_at: string
  notification?: {
    id: string
    event_key: string
    title: string
    user?: { id: string; full_name?: string; first_name?: string; last_name?: string } | null
  } | null
}

/** `GET /notification-log/health` — a 24-hour window, computed server-side. */
export type DeliveryHealth = {
  window_hours: number
  channels: Array<{
    channel: string
    enabled: boolean
    delivered: number
    failed: number
    suppressed: number
    pending: number
    /** Null when nothing was attempted — not zero, which would read as failure. */
    success_rate: number | null
  }>
}

export const commsKeys = {
  announcements: (drafts: boolean, page: number) => ['announcements', 'list', drafts, page] as const,
  announcement: (id: string) => ['announcements', 'detail', id] as const,
  notifications: (page: number) => ['notifications', 'list', page] as const,
  unread: ['notifications', 'unread'] as const,
  deliveries: (filters: DeliveryFilters) => ['notification-log', 'list', filters] as const,
  health: ['notification-log', 'health'] as const,
}

export const fetchAnnouncements = (includeDrafts: boolean, page = 1): Promise<Page<Announcement>> =>
  requestPage<Announcement>('/announcements', {
    query: { include_drafts: includeDrafts ? 1 : undefined, page, per_page: 20 },
  })

export const fetchAnnouncement = async (id: string): Promise<Announcement> =>
  (await request<Announcement>(`/announcements/${id}`)).data

export const fetchNotifications = (page = 1): Promise<Page<AppNotification>> =>
  requestPage<AppNotification>('/notifications', { query: { page, per_page: 20 } })

export const fetchUnreadCount = async (): Promise<number> => {
  const envelope = await request<{ unread: number } | number>('/notifications/unread-count')
  const data = envelope.data

  return typeof data === 'number' ? data : (data?.unread ?? 0)
}

export type DeliveryFilters = { channel?: string; status?: string; page?: number }

export const fetchDeliveries = (filters: DeliveryFilters): Promise<Page<Delivery>> =>
  requestPage<Delivery>('/notification-log', {
    query: {
      channel: filters.channel || undefined,
      status: filters.status || undefined,
      page: filters.page,
      per_page: 20,
    },
  })

export const fetchDeliveryHealth = async (): Promise<DeliveryHealth> =>
  (await request<DeliveryHealth>('/notification-log/health')).data

// ── mutations ──────────────────────────────────────────────────────────────

/** `content`, not `body`; `target_audience`, not `audience`. */
export const createAnnouncement = (body: {
  title: string
  content: string
  target_audience?: AnnouncementAudience
  priority?: AnnouncementPriority
  expires_at?: string
}) => request('/announcements', { method: 'POST', body })

export const updateAnnouncement = (
  id: string,
  body: Partial<{
    title: string
    content: string
    target_audience: AnnouncementAudience
    priority: AnnouncementPriority
    expires_at: string | null
  }>,
) => request(`/announcements/${id}`, { method: 'PUT', body })

export const publishAnnouncement = (id: string) =>
  request(`/announcements/${id}/publish`, { method: 'POST', body: {} })

export const withdrawAnnouncement = (id: string, reason: string) =>
  request(`/announcements/${id}/withdraw`, { method: 'POST', body: { reason } })

export const markNotificationRead = (id: string) =>
  request(`/notifications/${id}/read`, { method: 'PATCH', body: {} })

export const markAllNotificationsRead = () =>
  request('/notifications/read-all', { method: 'POST', body: {} })

export const resendDelivery = (id: string) =>
  request(`/notification-log/${id}/resend`, { method: 'POST', body: {} })

// ── presentation ───────────────────────────────────────────────────────────

export const AUDIENCES: AnnouncementAudience[] = ['ALL', 'STUDENTS', 'DRIVERS', 'ADMINS']
export const PRIORITIES: AnnouncementPriority[] = ['LOW', 'MEDIUM', 'HIGH']

/**
 * The audience in words somebody can weigh.
 *
 * "Publish" and "tell every student in the college" should not feel like the
 * same click, so the dialog says the second one.
 */
export const AUDIENCE_SENTENCE: Record<AnnouncementAudience, string> = {
  ALL: 'everybody who uses CTMS — students, drivers and staff',
  STUDENTS: 'every student registered for transport',
  DRIVERS: 'every driver',
  ADMINS: 'transport office staff only',
}

export function personName(
  person: { full_name?: string; first_name?: string; last_name?: string } | null | undefined,
): string {
  if (!person) return '—'

  return person.full_name || [person.first_name, person.last_name].filter(Boolean).join(' ') || '—'
}

export function humanise(value: string | null | undefined): string {
  if (!value) return '—'
  const words = value.replace(/_/g, ' ').toLowerCase()

  return words.charAt(0).toUpperCase() + words.slice(1)
}

export function whenText(value: string | null | undefined): string {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value

  return date.toLocaleString(undefined, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}

/** Draft, live or withdrawn — derived from the two columns the model has. */
export function announcementState(announcement: Announcement): 'draft' | 'live' | 'withdrawn' {
  if (!announcement.published_at) return 'draft'

  return announcement.is_active ? 'live' : 'withdrawn'
}
