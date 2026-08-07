<?php

namespace App\Providers;

use App\Contracts\Maps\GeocodingProvider;
use App\Contracts\Maps\PlacesProvider;
use App\Contracts\Maps\RoadsProvider;
use App\Contracts\Maps\RoutingProvider;
use App\Contracts\NotifiesUsers;
use App\Listeners\DispatchEventNotifications;
use App\Services\Maps\Providers\GoogleGeocodingProvider;
use App\Services\Maps\Providers\GooglePlacesProvider;
use App\Services\Maps\Providers\GoogleRoadsProvider;
use App\Services\Maps\Providers\GoogleRoutingProvider;
use App\Services\Maps\Providers\NullGeocodingProvider;
use App\Services\Maps\Providers\OfflineRoutingProvider;
use App\Services\Maps\Providers\PassthroughRoadsProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->configureMapProviders();
    }

    /**
     * Bind the map contracts (FR-09).
     *
     * Resolved from config at container-build time, so switching Google off is
     * a deployment decision rather than a code change. The offline bindings
     * are the default and are what the entire test suite runs against — which
     * means the path the system falls back to during an outage is the path
     * that is exercised on every run, rather than one nobody has executed.
     */
    private function configureMapProviders(): void
    {
        $useGoogle = (bool) config('services.google_maps.enabled', false)
            && (string) config('services.google_maps.key', '') !== '';

        $this->app->singleton(
            RoutingProvider::class,
            fn () => $useGoogle
                ? new GoogleRoutingProvider
                : new OfflineRoutingProvider,
        );

        $this->app->singleton(
            GeocodingProvider::class,
            fn () => $useGoogle
                ? new GoogleGeocodingProvider
                : new NullGeocodingProvider,
        );

        $this->app->singleton(
            PlacesProvider::class,
            fn () => $useGoogle
                ? new GooglePlacesProvider
                : new NullGeocodingProvider,
        );

        $this->app->singleton(
            RoadsProvider::class,
            fn () => $useGoogle
                ? new GoogleRoadsProvider
                : new PassthroughRoadsProvider,
        );
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureRateLimiting();
        $this->configureProductionHardening();
        $this->configureNotifications();
    }

    /**
     * Bridge every domain event to the notification platform.
     *
     * A wildcard listener rather than one registration per event: any event
     * implementing NotifiesUsers is picked up automatically, so adding an
     * event to a module never requires editing this provider — and cannot be
     * forgotten.
     */
    private function configureNotifications(): void
    {
        Event::listen('*', function (string $eventName, array $payload) {
            $event = $payload[0] ?? null;

            if (! $event instanceof NotifiesUsers) {
                return;
            }

            try {
                app(DispatchEventNotifications::class)->handle($event);
            } catch (\Throwable $e) {
                // BR-408 — the guard has to sit outside the listener as well
                // as inside it. If the platform cannot even be constructed,
                // the publishing module must still complete its work.
                Log::error('Notification bridge failed', [
                    'event' => $event::class,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Fail loudly on developer mistakes instead of silently misbehaving.
     */
    private function configureModels(): void
    {
        // If code tries to fill an attribute that is not in $fillable, throw
        // rather than quietly dropping it. This turns "the update silently did
        // nothing" bugs into immediate, visible failures.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Block destructive artisan commands (migrate:fresh, db:wipe) in prod.
        DB::prohibitDestructiveCommands($this->app->isProduction());
    }

    /**
     * Named rate limiters. Applied per-route via the `throttle:<name>` middleware.
     */
    private function configureRateLimiting(): void
    {
        // General authenticated API traffic.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // Credential endpoints. Keyed on email AND IP so one attacker cannot
        // lock out a victim by burning their email's quota, and cannot evade
        // the limit by rotating the email either.
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(5)->by('email:'.strtolower((string) $request->input('email'))),
            Limit::perMinute(20)->by('ip:'.$request->ip()),
        ]);

        // Password changes — low volume by nature.
        RateLimiter::for('password', fn (Request $request) => Limit::perMinute(5)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // GPS ingest runs every 5-10s per driver, so ~12 req/min is normal.
        // The ceiling stops a compromised device from flooding the pipeline.
        RateLimiter::for('gps', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // Mutating administrative operations.
        RateLimiter::for('writes', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
    }

    private function configureProductionHardening(): void
    {
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
