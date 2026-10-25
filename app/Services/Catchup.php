<?php

namespace App\Services;

use App\Models\Message;
use App\Models\User;
use App\Models\UserContact;
use Illuminate\Support\Collection;

/**
 * What the user missed while away: contacts with unread texts and missed calls (App\Services\Outreach), newest
 * first, and a short welcome-back line.
 */
class Catchup
{
    /** Away at least this long before a "welcome back". */
    public const WELCOME_BACK_SECONDS = 30 * 60;

    /**
     * @return Collection<int, array{entry: UserContact, unread: int, missed: int, latest: Message}>
     */
    public function summary(User $user): Collection
    {
        return $user->userContacts()->with('contact')->withUnreadCount()->get()
            ->filter(fn (UserContact $entry) => $entry->unread_count > 0)
            ->map(function (UserContact $entry) {
                $unread = $entry->messages()->where('sender', Message::FROM_CONTACT)->whereNull('read_at');

                return [
                    'entry' => $entry,
                    'unread' => (int) $entry->unread_count,
                    'missed' => (clone $unread)->where('meta->missed', true)->count(),
                    'latest' => $unread->latest()->latest('id')->firstOrFail(),
                ];
            })
            ->sort(fn (array $a, array $b) => [$b['latest']->created_at, $b['latest']->id] <=> [$a['latest']->created_at, $a['latest']->id])
            ->values();
    }

    /** "Welcome back! Maya texted you 2 times and called once." or null when nothing is waiting. */
    public function welcomeMessage(User $user): ?string
    {
        $rows = $this->summary($user);
        if ($rows->isEmpty()) {
            return null;
        }

        if ($rows->count() === 1) {
            $row = $rows->first();
            $texts = $row['unread'] - $row['missed'];
            $did = array_filter([
                $texts > 0 ? 'texted you'.($texts > 1 ? " {$texts} times" : '') : null,
                $row['missed'] > 0 ? ($texts > 0 ? 'called ' : 'called you ').($row['missed'] === 1 ? 'once' : "{$row['missed']} times") : null,
            ]);

            return "Welcome back! {$row['entry']->displayName()} ".implode(' and ', $did).'.';
        }

        $names = $rows->map(fn (array $row) => $row['entry']->displayName());
        $who = $names->count() === 2
            ? "{$names[0]} and {$names[1]}"
            : "{$names[0]}, {$names[1]} and ".($names->count() - 2).' '.($names->count() === 3 ? 'other' : 'others');

        return "Welcome back! {$who} reached out.";
    }
}
