import { useEffect, useMemo, useRef } from 'react'
import { APIProvider, Map, AdvancedMarker, useMap } from '@vis.gl/react-google-maps'
import { config } from '@/config/env'
import { Icon } from '@/icons/Icon'
import type { TrackedTrip } from './api'

/** Andhra Pradesh, so an empty map is not the middle of the Atlantic. */
const FALLBACK_CENTRE = { lat: 16.9891, lng: 82.2475 }

type Props = {
  tracked: TrackedTrip[]
  selectedId: string | null
  onSelect: (tripId: string) => void
}

/**
 * The live map.
 *
 * Renders positions the backend reported and nothing else. Routing, road
 * distance and ETA are all computed server-side; the browser key carries the
 * Maps JavaScript API alone and the panel asks Google for no other service.
 *
 * A missing key is a supported state, not a crash — the surrounding screen
 * keeps working and this says why the map does not.
 */
export function LiveMap({ tracked, selectedId, onSelect }: Props) {
  if (!config.hasMap) return <MapUnavailable />

  return (
    <APIProvider apiKey={config.mapsApiKey}>
      <Map
        mapId="ctms-live"
        defaultCenter={FALLBACK_CENTRE}
        defaultZoom={11}
        gestureHandling="greedy"
        disableDefaultUI
        zoomControl
        className="size-full"
      >
        <Markers tracked={tracked} selectedId={selectedId} onSelect={onSelect} />
        <FitOnce tracked={tracked} />
      </Map>
    </APIProvider>
  )
}

function Markers({ tracked, selectedId, onSelect }: Props) {
  return (
    <>
      {tracked.map(({ trip, live }) => {
        const position = live?.position
        // A trip with no reported position is NOT placed at 0,0 and NOT left
        // at wherever it was last seen. It simply has no marker, and the list
        // beside the map says so.
        if (!position) return null

        const selected = trip.id === selectedId
        const stale = position.is_stale

        return (
          <AdvancedMarker
            key={trip.id}
            position={{ lat: position.latitude, lng: position.longitude }}
            onClick={() => onSelect(trip.id)}
            title={`${trip.bus?.registration_number ?? 'Bus'} · ${trip.route?.route_name ?? ''}`}
            zIndex={selected ? 10 : 1}
          >
            <div
              className={[
                'flex items-center gap-xs rounded-sm border px-sm py-xs text-label font-semibold shadow-md',
                // Staleness is the server's `is_stale`, rendered — never a
                // second definition computed here from a timestamp.
                stale
                  ? 'border-stale-accent bg-surface text-on-surface-muted opacity-80'
                  : 'border-primary bg-primary text-on-primary',
                selected ? 'ring-2 ring-primary ring-offset-2' : '',
              ].join(' ')}
            >
              <span className="font-mono">{trip.bus?.registration_number ?? '—'}</span>
              {stale && <span className="font-normal">stale</span>}
            </div>
          </AdvancedMarker>
        )
      })}
    </>
  )
}

/**
 * Fits the tracked buses once, then leaves the viewport alone.
 *
 * Re-fitting on every refresh would drag an operator back to the whole fleet
 * each time they panned to look at a junction.
 */
function FitOnce({ tracked }: { tracked: TrackedTrip[] }) {
  const map = useMap()
  const fitted = useRef(false)

  const points = useMemo(
    () =>
      tracked
        .map(({ live }) => live?.position)
        .filter((position): position is NonNullable<typeof position> => Boolean(position)),
    [tracked],
  )

  useEffect(() => {
    if (!map || fitted.current || points.length === 0) return

    if (points.length === 1) {
      map.setCenter({ lat: points[0].latitude, lng: points[0].longitude })
      map.setZoom(13)
    } else {
      // Built from the extremes rather than google.maps.LatLngBounds, so this
      // file needs no ambient Google types for one call.
      const lats = points.map((point) => point.latitude)
      const lngs = points.map((point) => point.longitude)

      map.fitBounds(
        {
          north: Math.max(...lats),
          south: Math.min(...lats),
          east: Math.max(...lngs),
          west: Math.min(...lngs),
        },
        64,
      )
    }

    fitted.current = true
  }, [map, points])

  return null
}

/** The key is absent. Everything else on the screen still works. */
export function MapUnavailable() {
  return (
    <div className="grid size-full place-items-center bg-surface-sunken p-xl text-center">
      <div>
        <Icon name="offline" size="lg" className="text-on-surface-muted" />
        <p className="mt-md text-title-md">Map unavailable</p>
        <p className="mt-xs max-w-prose text-body text-on-surface-muted">
          Google Maps configuration is missing. Trip positions, stop progress and estimates are all still shown
          beside this panel.
        </p>
      </div>
    </div>
  )
}
