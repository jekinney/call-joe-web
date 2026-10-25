<?php

namespace App\Services;

use App\Models\Message;
use App\Models\User;
use App\Models\UserContact;
use Illuminate\Support\Collection;

/**
 * What the user missed while away: contacts with unread texts, newest first, and a short welcome-back line.
 * (Contacts reaching out on their own while the user is away comes in the outreach phase; it lands here too.)
 */
class Catchup
{
    /** Away at least this long before a "welcome back". */
    public const WELCOME_BACK_SECONDS = 30 * 60;

    /**
     * @return Collection<int, array{entry: UserContact, unread: int, latest: Message}>
     */
    public function summary(User $user): Collection
    {
        return $user->userContacts()->with('contact')->withUnreadCount()->get()
            ->filter(fn (UserContact $entry) => $entry->unread_count > 0)
            ->map(fn (UserContact $entry) => [
                'entry' => $entry,
                'unread' => (int) $entry->unread_count,
                'latest' => $entry->messages()->where('sender', Message::FROM_CONTACT)->whereNull('read_at')->latest('id')->firstOrFail(),
            ])
            ->sortByDesc(fn (array $row) => $row['latest']->id)
            ->values();
    }

    /** "Welcome back! Maya texted you 2 times." or null when nothing is waiting. */
    public function welcomeMessage(User $user): ?string
    {
        $rows = $this->summary($user);
        if ($rows->isEmpty()) {
            return null;
        }

        if ($rows->count() === 1) {
            $row = $rows->first();
            $times = $row['unread'] === 1 ? 'texted you' : "texted you {$row['unread']} times";

            return "Welcome back! {$row['entry']->displayName()} {$times}.";
        }

        $names = $rows->map(fn (array $row) => $row['entry']->displayName());
        $who = $names->count() === 2
            ? "{$names[0]} and {$names[1]}"
            : "{$names[0]}, {$names[1]} and ".($names->count() - 2).' '.($names->count() === 3 ? 'other' : 'others');

        return "Welcome back! {$who} texted you.";
    }
}
