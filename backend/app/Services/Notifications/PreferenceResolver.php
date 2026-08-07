<?php

namespace App\Services\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Models\NotificationPreference;
use App\Models\User;

/**
 * Decides which channels a given notification may use for a given person
 * (BR-402, BR-403, BR-404).
 *
 * The whole of the "should we bother them?" question lives here, so no caller
 * has to remember that critical notifications ignore quiet hours.
 */
class PreferenceResolver
{
    /**
     * Channels to attempt, and the reason for each channel that was excluded.
     *
     * Suppression reasons are returned rather than discarded because a
     * suppressed notification is recorded, not silent (BR-407): "why didn't I
     * get told?" must be answerable.
     *
     * @return array{channels: array<int, NotificationChannel>, suppressed: array<string, string>}
     */
    public function resolve(
        User $user,
        NotificationCategory $category,
        NotificationPriority $priority,
    ): array {
        $preference = $this->preferenceFor($user, $category);

        $selected = $preference?->selectedChannels() ?? $category->defaultChannels();
        $muted = $preference?->muted ?? false;

        $channels = [];
        $suppressed = [];

        foreach (NotificationChannel::cases() as $channel) {
            $verdict = $this->verdictFor($user, $category, $priority, $channel, $selected, $muted);

            if ($verdict === null) {
                $channels[] = $channel;
            } else {
                $suppressed[$channel->value] = $verdict;
            }
        }

        return ['channels' => $channels, 'suppressed' => $suppressed];
    }

    /**
     * Null means "send on this channel"; a string is the reason not to.
     *
     * @param  array<int, NotificationChannel>  $selected
     */
    private function verdictFor(
        User $user,
        NotificationCategory $category,
        NotificationPriority $priority,
        NotificationChannel $channel,
        array $selected,
        bool $muted,
    ): ?string {
        // The in-app record is always written. It is the history of what the
        // system told someone; suppressing it because push was muted would
        // leave them unable to find a message they heard about elsewhere.
        if ($channel->isAlwaysDelivered()) {
            return $channel->isEnabled() ? null : 'Channel is disabled for this installation.';
        }

        if (! $channel->isEnabled()) {
            return 'Channel is disabled for this installation.';
        }

        // BR-402 — critical notifications ignore every preference below.
        if ($priority->overridesPreferences()) {
            return $this->hasAddressFor($user, $channel)
                ? null
                : 'No delivery address registered for this channel.';
        }

        // BR-404 — a non-mutable category cannot have been muted, but a stale
        // preference row could still say so. The category wins.
        if ($muted && $category->isMutable()) {
            return 'The recipient has muted this category.';
        }

        if (! in_array($channel, $selected, true)) {
            return 'The recipient has not selected this channel for this category.';
        }

        if ($this->isWithinQuietHours($user)) {
            return 'Within the recipient\'s quiet hours.';
        }

        if (! $this->hasAddressFor($user, $channel)) {
            return 'No delivery address registered for this channel.';
        }

        return null;
    }

    private function preferenceFor(User $user, NotificationCategory $category): ?NotificationPreference
    {
        return NotificationPreference::where('user_id', $user->getKey())
            ->where('category', $category->value)
            ->first();
    }

    /**
     * Whether the user can actually be reached on this channel at all.
     */
    private function hasAddressFor(User $user, NotificationChannel $channel): bool
    {
        return match ($channel) {
            NotificationChannel::PUSH => $user->notificationDevices()->active()->exists(),
            NotificationChannel::SMS => filled($user->phone_number),
            NotificationChannel::EMAIL => filled($user->email),
            NotificationChannel::IN_APP => true,
        };
    }

    /**
     * Quiet hours may wrap midnight — 22:00 to 07:00 is one window, not two.
     */
    public function isWithinQuietHours(User $user): bool
    {
        $start = $user->quiet_hours_start ?? config('ctms.notifications.default_quiet_hours.start');
        $end = $user->quiet_hours_end ?? config('ctms.notifications.default_quiet_hours.end');

        if (blank($start) || blank($end)) {
            return false;
        }

        $now = now()->format('H:i:s');
        $start = $this->normaliseTime($start);
        $end = $this->normaliseTime($end);

        if ($start === $end) {
            return false; // A zero-length window silences nothing.
        }

        return $start < $end
            ? ($now >= $start && $now < $end)
            : ($now >= $start || $now < $end);
    }

    private function normaliseTime(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : $time;
    }
}
