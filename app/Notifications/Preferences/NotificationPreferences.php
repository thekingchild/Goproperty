<?php

declare(strict_types=1);

namespace App\Notifications\Preferences;

use App\Models\User;

/**
 * An account's notification choices (P05-02; PLATFORM_CONSOLE.md § 5
 * "Categories": reminders follow per-user channel preferences; security
 * and transactional messages are always sent). Kept in `users.preferences`
 * as {"reminders": {"email": bool, "sms": bool}}; anything unset is on.
 * In-app notices cannot be turned off: they are the portal's own record.
 */
final class NotificationPreferences
{
    public const string REMINDERS = 'reminders';

    /** The channels a person may turn off, per category. */
    public const array CHOICES = [
        self::REMINDERS => ['email', 'sms'],
    ];

    public function allows(?User $user, string $category, string $channel): bool
    {
        if ($user === null || ! in_array($channel, self::CHOICES[$category] ?? [], true)) {
            return true;
        }

        $value = data_get($user->preferences ?? [], $category.'.'.$channel);

        return $value !== false;
    }

    /** @param  array<string, bool>  $channels  e.g. ['email' => true, 'sms' => false] */
    public function set(User $user, string $category, array $channels): void
    {
        $preferences = is_array($user->preferences) ? $user->preferences : [];

        foreach (self::CHOICES[$category] ?? [] as $channel) {
            if (array_key_exists($channel, $channels)) {
                $preferences[$category][$channel] = (bool) $channels[$channel];
            }
        }

        $user->forceFill(['preferences' => $preferences])->save();
    }
}
