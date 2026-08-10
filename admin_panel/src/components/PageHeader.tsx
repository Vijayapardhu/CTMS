import type { ReactNode } from 'react'

type Props = {
  title: string
  subtitle?: string
  /** Breadcrumb region — a slice that needs it fills this. */
  breadcrumb?: ReactNode
  actions?: ReactNode
}

/** C4 PageHeader. */
export function PageHeader({ title, subtitle, breadcrumb, actions }: Props) {
  return (
    <div className="mb-xl flex items-start gap-lg">
      <div className="min-w-0">
        {breadcrumb && <div className="mb-xs text-label text-on-surface-muted">{breadcrumb}</div>}
        <h1 className="text-title-lg font-semibold">{title}</h1>
        {subtitle && <p className="mt-xs text-body text-on-surface-muted">{subtitle}</p>}
      </div>
      {actions && <div className="ml-auto flex shrink-0 items-center gap-sm">{actions}</div>}
    </div>
  )
}
