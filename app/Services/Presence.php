<?php

namespace App\Services;

use App\Models\User;

/**
 * Whether a user is online right now: their open page checks in every HEARTBEAT_SECONDS (resources/js/presence.js).
 * Contacts decide on this whether a call rings live or becomes a missed call, and whether a text arrives live.
 */
class Presence
{
    /** How often an open page checks in. */
    public const HEARTBEAT_SECONDS = 45;

    /** Online means a check-in this recently (two missed heartbeats and a little slack). */
    public const ONLINE_SECONDS = 100;

    /** Check-ins closer together than this don't write to the database again. */
    public const WRITE_EVERY_SECONDS = 30;

    public function touch(User $user): void
    {
        if ($user->last_seen_at && $user->last_seen_at->gt(now()->subSeconds(self::WRITE_EVERY_SECONDS))) {
            return;
        }

        $user->forceFill(['last_seen_at' => now()])->saveQuietly();
    }

    public function isOnline(User $user): bool
    {
        return $user->last_seen_at !== null && $user->last_seen_at->gt(now()->subSeconds(self::ONLINE_SECONDS));
    }
}
