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

    /**
     * Records a check-in. Returns how many seconds they'd been away if they were offline until now (null if they
     * were already online, or this is their very first visit).
     */
    public function touch(User $user): ?int
    {
        $last = $user->last_seen_at;
        if ($last && $last->gt(now()->subSeconds(self::WRITE_EVERY_SECONDS))) {
            return null;
        }

        $away = $last && ! $this->isOnline($user) ? (int) $last->diffInSeconds(now(), true) : null;
        $user->forceFill(['last_seen_at' => now()])->saveQuietly();

        return $away;
    }

    public function isOnline(User $user): bool
    {
        return $user->last_seen_at !== null && $user->last_seen_at->gt(now()->subSeconds(self::ONLINE_SECONDS));
    }
}
