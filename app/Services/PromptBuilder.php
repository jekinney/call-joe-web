<?php

namespace App\Services;

use App\Models\PromptRule;
use App\Models\Relationship;
use App\Models\UserContact;
use App\Support\PromptSnapshot;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Builds a contact's system prompt for one user, always in the same order:
 *   1. rules every contact follows (prompt_rules)
 *   2. the contact's persona
 *   3. who they are to the user (relationship), and whether romance is ever part of it
 *   4. about the user
 *   5. what the contact remembers about the user
 *   6. the time where the user is (last, so everything above stays the same between conversations)
 * The same inputs always give the same text and hash: build it once at the start of a conversation and keep it.
 */
class PromptBuilder
{
    public function build(UserContact $entry, ?string $memory = null, ?DateTimeInterface $at = null): PromptSnapshot
    {
        $user = $entry->user;
        $contact = $entry->contact;
        $relationship = $entry->relationship ?? Relationship::bySlug('friend');
        $buddy = $user->preferredName();
        $contactName = $contact->firstName();
        $now = CarbonImmutable::instance($at ?? now())->setTimezone($user->timezone);

        $sections = [
            'rules' => "# Rules you always follow\n\n".PromptRule::orderBy('sort')->get()
                ->map(fn (PromptRule $rule) => "## {$rule->title}\n".trim($rule->body_md))
                ->implode("\n\n"),
            'persona' => trim($contact->persona_md),
            'relationship' => "# Who you are to {$buddy}\n".trim($relationship->promptFor($contactName, $buddy))."\n".($relationship->allows_romance
                ? 'Romance is part of this relationship: be affectionate and warm, suggestive at most, never explicit.'
                : "Never flirt or be romantic with {$buddy}."),
            'buddy' => "# About {$buddy}\n- They go by {$buddy} (full name: {$user->name}).\n- They live in the {$user->timezone} timezone.",
            'memory' => "# What you remember about {$buddy}\n".(trim((string) $memory) !== ''
                ? trim((string) $memory)
                : "You don't know much about {$buddy} yet. Get to know them, and remember what they tell you."),
            'now' => "# Right now\nIt's {$now->format('l, F j, Y, g:i A')} where {$buddy} is.",
        ];

        $text = implode("\n\n", $sections);

        return new PromptSnapshot($text, hash('sha256', $text), $sections);
    }
}
