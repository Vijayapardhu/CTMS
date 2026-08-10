import { HugeiconsIcon } from '@hugeicons/react'
import { AppIcon, type AppIconName } from './registry'

/** The sizes components actually use — 07-design-system.md. */
export const IconSize = {
  xs: 16,
  sm: 20,
  md: 24,
  lg: 28,
} as const

type Props = {
  name: AppIconName
  size?: keyof typeof IconSize
  className?: string
  /** Given only when the icon carries meaning no nearby text carries. */
  label?: string
}

/**
 * Every icon in the panel goes through here.
 *
 * A decorative icon is hidden from assistive technology; one that carries
 * meaning gets a label. Icons are never the only carrier of a status — the
 * design system pairs each semantic colour with a word as well, because
 * roughly eight percent of male staff have a colour vision deficiency.
 */
export function Icon({ name, size = 'sm', className, label }: Props) {
  const symbol = AppIcon[name]

  if (!symbol) {
    // A missing symbol must not blank the interface. It leaves a gap the
    // registry test is there to catch first.
    return <span className={className} aria-hidden="true" style={{ width: IconSize[size], height: IconSize[size] }} />
  }

  return (
    <HugeiconsIcon
      icon={symbol}
      size={IconSize[size]}
      className={className}
      strokeWidth={1.5}
      role={label ? 'img' : undefined}
      aria-label={label}
      aria-hidden={label ? undefined : true}
    />
  )
}
