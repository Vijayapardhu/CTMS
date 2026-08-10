import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Icon } from '@/icons/Icon'
import { attendanceKeys, fetchManifest } from './api'

/**
 * Who was expected at a stop, and who actually got on.
 *
 * Fetched on demand, one stop at a time. A trip has a dozen stops and this is
 * a request each; filling every one on load would be twelve calls to answer a
 * question nobody asked.
 *
 * The service returns name, registration number and boarded — and nothing
 * else. There is no address, no phone number and no route history here,
 * because a stop roster does not need them and this screen is not the place to
 * assemble a profile of a student.
 */
export function StopManifestPanel({
  tripId,
  stopId,
  stopName,
}: {
  tripId: string
  stopId: string
  stopName: string
}) {
  const [open, setOpen] = useState(false)
  const manifest = useQuery({
    queryKey: attendanceKeys.manifest(tripId, stopId),
    queryFn: () => fetchManifest(tripId, stopId),
    enabled: open,
  })

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen((was) => !was)}
        aria-expanded={open}
        aria-label={`Who boarded at ${stopName}`}
        className="text-label font-semibold text-primary"
      >
        {open ? 'Hide' : 'Who boarded'}
      </button>

      {open && (
        <div className="basis-full pt-sm">
          {manifest.isPending && <div className="h-16 animate-pulse rounded-sm bg-surface-sunken" />}

          {manifest.isError && (
            <p className="text-label text-on-surface-muted">The roster for this stop could not be loaded.</p>
          )}

          {manifest.data && (
            <>
              <p className="text-label text-on-surface-muted">
                {manifest.data.boarded_count} of {manifest.data.expected_count} expected passengers boarded
              </p>

              {manifest.data.expected.length === 0 ? (
                <p className="mt-xs text-label text-on-surface-muted">Nobody is assigned to this stop.</p>
              ) : (
                <ul className="mt-xs rounded-sm border border-outline">
                  {manifest.data.expected.map((student) => (
                    <li
                      key={student.student_id}
                      className="flex items-center gap-sm border-b border-outline px-md py-xs text-label last:border-0"
                    >
                      <Icon
                        name={student.boarded ? 'success' : 'blocked'}
                        size="xs"
                        className={student.boarded ? 'text-positive' : 'text-on-surface-muted'}
                      />
                      <span>{student.name ?? 'Unnamed'}</span>
                      <span className="ml-auto font-mono text-on-surface-muted">
                        {student.registration_number ?? '—'}
                      </span>
                      <span className={student.boarded ? 'text-positive' : 'text-caution'}>
                        {student.boarded ? 'boarded' : 'did not board'}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </>
          )}
        </div>
      )}
    </>
  )
}
