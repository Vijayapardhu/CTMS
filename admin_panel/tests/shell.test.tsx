import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { AppShell } from '@/app/shell/AppShell'
import { AccessLevel } from '@/auth/accessLevel'
import { Placeholder } from '@/components/Placeholder'

function renderShell(level: AccessLevel | null) {
  return render(
    <MemoryRouter>
      <AppShell level={level}>
        <Placeholder title="Dashboard" icon="dashboard" slice="slice 2" />
      </AppShell>
    </MemoryRouter>,
  )
}

describe('the shell', () => {
  it('renders navigation and content together', () => {
    renderShell(AccessLevel.SUPER_ADMIN)

    expect(screen.getByRole('navigation', { name: 'Sections' })).toBeInTheDocument()
    expect(screen.getByRole('main')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Dashboard' })).toBeInTheDocument()
  })

  it('shows administration only to a super admin', () => {
    renderShell(AccessLevel.SUPER_ADMIN)
    expect(screen.getByRole('link', { name: /audit/i })).toBeInTheDocument()

    renderShell(AccessLevel.OPERATIONS)
    // Rendered twice now; the second shell must not carry Administration.
    expect(screen.getAllByRole('link', { name: /audit/i })).toHaveLength(1)
  })

  it('offers nothing at all while the session is unknown', () => {
    renderShell(null)

    // Authorization flicker: privileged navigation must never appear for the
    // moment before the access level arrives.
    expect(screen.queryByRole('link', { name: /dashboard/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /audit/i })).not.toBeInTheDocument()
  })
})
