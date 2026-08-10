import { PageHeader } from './PageHeader'
import { Icon } from '@/icons/Icon'
import type { AppIconName } from '@/icons/registry'

/**
 * A screen the route table knows about and a later slice will fill.
 *
 * Explicit rather than an empty div: a blank screen is indistinguishable from
 * one that failed to load, and this one says which it is.
 */
export function Placeholder({ title, icon, slice }: { title: string; icon: AppIconName; slice: string }) {
  return (
    <>
      <PageHeader title={title} />
      <div className="grid place-items-center rounded-md border border-dashed border-outline bg-surface p-xxl">
        <Icon name={icon} size="lg" className="text-on-surface-muted" />
        <p className="mt-md text-title-md">Arriving in {slice}</p>
        <p className="mt-xs text-body text-on-surface-muted">
          The route, the shell and the permissions for this screen are in place.
        </p>
      </div>
    </>
  )
}
