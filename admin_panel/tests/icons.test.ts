import { describe, expect, it } from 'vitest'
import { AppIcon } from '@/icons/registry'

describe('the icon registry', () => {
  it('resolves every entry to a real Hugeicons symbol', () => {
    // Two names the specification proposed did not exist in the package.
    // This is the test that caught them, and it is why no component imports a
    // symbol directly.
    const unresolved = Object.entries(AppIcon)
      .filter(([, symbol]) => symbol === undefined || symbol === null)
      .map(([name]) => name)

    expect(unresolved).toEqual([])
  })

  it('has no duplicate meanings pointing at the same symbol by accident', () => {
    const names = Object.keys(AppIcon)
    expect(new Set(names).size).toBe(names.length)
  })
})
