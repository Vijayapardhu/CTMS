<?php

namespace Tests\Unit\Events;

use App\Contracts\NotifiesUsers;
use App\Notifications\NotificationIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * A guard against silent notification loss.
 *
 * BR-408 requires that a failure in the notification platform never breaks the
 * operation that published the event, so the wildcard bridge catches
 * everything an event throws and logs it. That is correct, and it has a cost:
 * a mistyped enum constant inside `notificationIntents()` produces *no
 * notification at all* and no visible error. It happened twice while Module 4D
 * was being built — `NotificationCategory::OPERATIONS` and
 * `NotificationPriority::NORMAL`, neither of which exists — and in both cases
 * the only symptom was a test asserting a message that never arrived.
 *
 * This test walks every event implementing NotifiesUsers and constructs it
 * with empty models, so a reference to a constant that does not exist is a
 * fatal error here rather than a missing message in production.
 */
class EventContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, class-string>
     */
    private function notifyingEvents(): array
    {
        $found = [];
        $base = app_path('Events');

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace([$base.DIRECTORY_SEPARATOR, '.php'], '', $file->getPathname());
            $class = 'App\\Events\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->implementsInterface(NotifiesUsers::class)) {
                continue;
            }

            $found[] = $class;
        }

        sort($found);

        return $found;
    }

    /**
     * Whether an Error names a symbol that does not exist, as opposed to a
     * model this test declined to populate.
     */
    private function isBrokenSymbol(string $message): bool
    {
        foreach ([
            'Undefined constant',
            'Call to undefined method',
            'Call to undefined function',
            'not found',
            'Unknown named parameter',
            'Too few arguments',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    #[Test]
    public function every_notifying_event_is_discoverable(): void
    {
        // If this drops to zero the walk below silently passes and this whole
        // guard becomes decorative.
        $this->assertGreaterThan(10, count($this->notifyingEvents()));
    }

    #[Test]
    public function every_notifying_event_declares_a_unique_event_key(): void
    {
        $keys = [];

        foreach ($this->notifyingEvents() as $class) {
            $reflection = new ReflectionClass($class);

            $event = $reflection->newInstanceWithoutConstructor();

            try {
                $key = $event->eventKey();
            } catch (\Error $e) {
                if ($this->isBrokenSymbol($e->getMessage())) {
                    $this->fail("{$class}::eventKey() references something that does not exist: {$e->getMessage()}");
                }

                // Some keys vary with the event's payload (an expiry warning
                // reads differently once a document has actually lapsed).
                // Those cannot be resolved without a populated model.
                continue;
            }

            $this->assertNotSame('', $key, "{$class} has an empty event key.");

            // A duplicate key means two different events dedup against each
            // other, and one of them silently stops being delivered.
            $this->assertArrayNotHasKey(
                $key,
                $keys,
                "Event key '{$key}' is declared by both {$class} and ".($keys[$key] ?? '?').'.',
            );

            $keys[$key] = $class;
        }
    }

    #[Test]
    public function every_notifying_event_can_build_its_intents(): void
    {
        foreach ($this->notifyingEvents() as $class) {
            $reflection = new ReflectionClass($class);

            $event = $reflection->newInstanceWithoutConstructor();

            try {
                // With no models attached, every event should return an empty
                // list rather than raising. What must never happen is an
                // Error — that means a constant or method that does not exist,
                // and the bridge would swallow it in production.
                $intents = $event->notificationIntents();
            } catch (\Error $e) {
                // An event built without its models will trip over an
                // uninitialised property or a null relation, and that is an
                // artifact of how this test constructs it — not a defect.
                // A missing *symbol* is a defect, and is the one this test
                // exists to catch.
                if ($this->isBrokenSymbol($e->getMessage())) {
                    $this->fail(
                        "{$class}::notificationIntents() references something that does not exist: "
                        ."{$e->getMessage()}. The BR-408 guard would swallow this in production "
                        .'and the notification would silently never arrive.'
                    );
                }

                continue;
            } catch (\Throwable) {
                // A domain exception from an uninitialised model is harmless
                // here.
                continue;
            }

            $this->assertIsArray($intents, "{$class} did not return an array of intents.");

            foreach ($intents as $intent) {
                $this->assertInstanceOf(NotificationIntent::class, $intent);
            }
        }
    }
}
