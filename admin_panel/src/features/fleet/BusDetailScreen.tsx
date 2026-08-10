import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { StatusChip } from '@/components/StatusChip'
import { Icon } from '@/icons/Icon'
import type { ApiFailure } from '@/api/failure'
import { BusStatusChip } from './FleetScreen'
import {
  capacity,
  daysUntil,
  fetchBus,
  fetchDocuments,
  fetchInspections,
  fetchReadiness,
  fleetKeys,
  inspectorName,
  odometer,
  readableDocumentType,
  shortDate,
  type Inspection,
  type ServiceReadiness,
} from './api'

/**
 * A7 Bus details.
 *
 * Four reads, all for one bus, all on demand — which is why the fleet table
 * does not carry a readiness column. Nothing here mutates: assignment,
 * grounding and maintenance are deliberately not offered, because this slice
 * is about reading and explaining fleet state.
 */
export function BusDetailScreen() {
  const { id = '' } = useParams()

  const bus = useQuery({ queryKey: fleetKeys.bus(id), queryFn: () => fetchBus(id), enabled: Boolean(id) })
  const readiness = useQuery({
    queryKey: fleetKeys.readiness(id),
    queryFn: () => fetchReadiness(id),
    enabled: Boolean(id),
  })
  const inspections = useQuery({
    queryKey: fleetKeys.inspections(id),
    queryFn: () => fetchInspections(id),
    enabled: Boolean(id),
  })
  const documents = useQuery({
    queryKey: fleetKeys.documents(id),
    queryFn: () => fetchDocuments(id),
    enabled: Boolean(id),
  })

  if (bus.isPending) {
    return (
      <>
        <PageHeader title="Bus" />
        <div className="h-64 animate-pulse rounded-md bg-surface-sunken" />
      </>
    )
  }

  if (bus.isError) {
    const failure = bus.error as ApiFailure

    return (
      <>
        <PageHeader title="Bus" />
        <div className="rounded-md border border-outline bg-surface p-xxl text-center">
          <Icon name="warning" size="lg" className="text-caution" />
          <p className="mt-md text-title-md">{failure?.displayMessage ?? 'This bus could not be loaded.'}</p>
          <Link to="/buses" className="mt-lg inline-block text-body text-primary">
            ← Back to the fleet
          </Link>
        </div>
      </>
    )
  }

  const value = bus.data!

  return (
    <>
      <PageHeader
        title={value.registration_number}
        subtitle={[value.vehicle_name, value.model].filter(Boolean).join(' · ')}
        breadcrumb={
          <Link to="/buses" className="text-primary">
            ← Fleet
          </Link>
        }
        actions={<BusStatusChip status={value.status} />}
      />

      <div className="grid grid-cols-1 gap-lg xl:grid-cols-2">
        <section className="rounded-md border border-outline bg-surface">
          <h2 className="border-b border-outline px-lg py-md text-title-md font-semibold">Vehicle</h2>
          <dl className="px-lg py-sm">
            <Row label="Registration">
              <span className="font-mono">{value.registration_number}</span>
            </Row>
            <Row label="Model">{value.model ?? '—'}</Row>
            <Row label="Capacity">{capacity(value)}</Row>
            <Row label="Odometer">
              <span className="font-mono">{odometer(value)}</span>
            </Row>
            {value.year_of_manufacture && <Row label="Year">{value.year_of_manufacture}</Row>}
            {value.fuel_type && <Row label="Fuel">{value.fuel_type}</Row>}
            <Row label="Last maintenance">{shortDate(value.last_maintenance_date)}</Row>
            <Row label="Next maintenance due">{shortDate(value.next_maintenance_due)}</Row>
          </dl>
        </section>

        <Readiness query={readiness} />
      </div>

      <Inspections query={inspections} />
      <Documents query={documents} />
    </>
  )
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex justify-between gap-lg border-b border-outline py-sm last:border-0">
      <dt className="text-body text-on-surface-muted">{label}</dt>
      <dd className="text-right text-body font-semibold">{children}</dd>
    </div>
  )
}

/**
 * The authority on whether this bus may go out.
 *
 * **Not** inferred from `bus.status` — a bus can be AVAILABLE and still fail
 * its readiness check, and that gap is the entire reason this endpoint exists.
 * Every reason the server gives is rendered, in the server's own words: the
 * driver sees these exact sentences on the bus, and two people reading two
 * different accounts of the same vehicle is how a defect gets driven.
 */
function Readiness({
  query,
}: {
  query: { data?: ServiceReadiness; isPending: boolean; isError: boolean; refetch: () => unknown }
}) {
  return (
    <section className="rounded-md border border-outline bg-surface">
      <h2 className="border-b border-outline px-lg py-md text-title-md font-semibold">Service readiness</h2>

      {query.isPending && <div className="m-lg h-24 animate-pulse rounded-sm bg-surface-sunken" />}

      {query.isError && (
        <div className="p-lg">
          <p className="text-body text-on-surface-muted">Readiness could not be checked.</p>
          <button
            type="button"
            onClick={() => void query.refetch()}
            className="mt-sm rounded-sm border border-outline px-md py-xs text-body"
          >
            Retry
          </button>
        </div>
      )}

      {query.data && (
        <div className="p-lg">
          {query.data.cleared ? (
            <>
              <StatusChip label="Ready for service" tone="positive" icon="success" />
              <p className="mt-md text-body text-on-surface-muted">
                Every check the office requires has been satisfied today.
              </p>
            </>
          ) : (
            <>
              <StatusChip label="Not ready" tone="critical" icon="blocked" />
              <h3 className="mt-lg mb-sm text-body font-semibold">Why this bus is not ready</h3>
              <ul className="flex flex-col gap-xs">
                {query.data.reasons.map((reason) => (
                  <li key={reason} className="flex items-start gap-sm text-body">
                    <Icon name="warning" size="xs" className="mt-xs shrink-0 text-caution" />
                    <span>{reason}</span>
                  </li>
                ))}
              </ul>
            </>
          )}

          {query.data.inspection && (
            <p className="mt-lg text-label text-on-surface-muted">
              Last inspection {shortDate(query.data.inspection.inspected_on)} ·{' '}
              {query.data.inspection.outcome.replace(/_/g, ' ').toLowerCase()}
            </p>
          )}
        </div>
      )}
    </section>
  )
}

function Inspections({
  query,
}: {
  query: { data?: { rows: Inspection[] }; isPending: boolean; isError: boolean }
}) {
  return (
    <section className="mt-lg rounded-md border border-outline bg-surface">
      <h2 className="border-b border-outline px-lg py-md text-title-md font-semibold">Inspection history</h2>

      {query.isPending && <div className="m-lg h-24 animate-pulse rounded-sm bg-surface-sunken" />}
      {query.isError && <p className="p-lg text-body text-on-surface-muted">Inspections could not be loaded.</p>}

      {query.data && query.data.rows.length === 0 && (
        <p className="p-lg text-body text-on-surface-muted">This bus has never been inspected.</p>
      )}

      {query.data && query.data.rows.length > 0 && (
        <ul className="p-lg">
          {query.data.rows.map((inspection) => {
            const failed = (inspection.items ?? []).filter((item) => !item.passed)

            return (
              <li key={inspection.id} className="border-b border-outline py-sm last:border-0">
                <div className="flex flex-wrap items-baseline gap-md">
                  <span className="font-semibold">{shortDate(inspection.inspected_on)}</span>
                  <StatusChip
                    label={inspection.outcome.replace(/_/g, ' ').toLowerCase()}
                    tone={
                      inspection.outcome === 'PASSED'
                        ? 'positive'
                        : inspection.outcome === 'FAILED'
                          ? 'critical'
                          : 'caution'
                    }
                    icon={inspection.outcome === 'PASSED' ? 'success' : 'warning'}
                  />
                  <span className="font-mono text-label text-on-surface-muted">
                    {inspection.odometer_reading?.toLocaleString() ?? '—'} km
                  </span>
                  <span className="ml-auto text-label text-on-surface-muted">{inspectorName(inspection)}</span>
                </div>

                {failed.length > 0 && (
                  <ul className="mt-xs flex flex-wrap gap-sm">
                    {failed.map((item) => (
                      <li key={item.id} className="text-label text-critical">
                        {item.item.replace(/_/g, ' ').toLowerCase()}
                        {item.notes && <span className="text-on-surface-muted"> — {item.notes}</span>}
                      </li>
                    ))}
                  </ul>
                )}
              </li>
            )
          })}
        </ul>
      )}
    </section>
  )
}

/**
 * Statutory documents.
 *
 * `file_path` is deliberately never rendered as a link: the evidence and
 * document store is private, and turning a storage path into a URL here would
 * be inventing a public route the backend does not offer.
 */
function Documents({
  query,
}: {
  query: { data?: import('./api').BusDocuments; isPending: boolean; isError: boolean }
}) {
  return (
    <section className="mt-lg rounded-md border border-outline bg-surface">
      <div className="flex items-center gap-lg border-b border-outline px-lg py-md">
        <h2 className="text-title-md font-semibold">Documents</h2>
        {query.data && (
          <span className="ml-auto">
            {query.data.compliance.is_compliant ? (
              <StatusChip label="Compliant" tone="positive" icon="success" />
            ) : (
              <StatusChip label="Not compliant" tone="critical" icon="blocked" />
            )}
          </span>
        )}
      </div>

      {query.isPending && <div className="m-lg h-24 animate-pulse rounded-sm bg-surface-sunken" />}
      {query.isError && <p className="p-lg text-body text-on-surface-muted">Documents could not be loaded.</p>}

      {query.data && query.data.compliance.missing_or_expired.length > 0 && (
        <p className="px-lg pt-md text-body text-critical">
          Missing or expired: {query.data.compliance.missing_or_expired.map(readableDocumentType).join(', ')}
        </p>
      )}

      {query.data && query.data.documents.length === 0 && (
        <p className="p-lg text-body text-on-surface-muted">No documents are recorded for this bus.</p>
      )}

      {query.data && query.data.documents.length > 0 && (
        <div className="overflow-x-auto">
          <table className="w-full text-body">
            <thead>
              <tr className="border-b border-outline text-left text-label text-on-surface-muted uppercase">
                <th scope="col" className="h-[var(--size-row-header)] px-lg font-medium">
                  Document
                </th>
                <th scope="col" className="px-lg font-medium">
                  Number
                </th>
                <th scope="col" className="px-lg font-medium">
                  Issued
                </th>
                <th scope="col" className="px-lg font-medium">
                  Expires
                </th>
              </tr>
            </thead>
            <tbody>
              {query.data.documents.map((document) => {
                const days = daysUntil(document.expires_on)

                return (
                  <tr key={document.id} className="h-[var(--size-row)] border-b border-outline last:border-0">
                    <td className="px-lg font-semibold">{readableDocumentType(document.document_type)}</td>
                    <td className="px-lg font-mono">{document.document_number ?? '—'}</td>
                    <td className="px-lg">{shortDate(document.issued_on)}</td>
                    <td className="px-lg">
                      {shortDate(document.expires_on)}
                      {days !== null && days < 30 && (
                        <span className={`ml-sm text-label font-semibold ${days < 0 ? 'text-critical' : 'text-caution'}`}>
                          {days < 0 ? 'expired' : `in ${days} days`}
                        </span>
                      )}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}
