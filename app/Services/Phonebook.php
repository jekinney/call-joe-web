<?php

namespace App\Services;

use App\Enums\AddCheck;
use App\Models\Contact;
use App\Models\Relationship;
use App\Models\User;
use App\Models\UserContact;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The contacts on a user's phone: adding (within the plan's contact limit, and only contacts their plan includes)
 * and removing (archived, never deleted, so everything they shared comes back if they're added again).
 */
class Phonebook
{
    public function __construct(private Entitlements $entitlements) {}

    /** Contacts on their phone now (removed ones don't count). */
    public function count(User $user): int
    {
        return $user->userContacts()->count();
    }

    /** How many contacts their plan allows at once; null means no limit. */
    public function limit(User $user): ?int
    {
        $limit = $this->entitlements->setting($user, 'contacts');

        return $limit === null ? null : (int) $limit;
    }

    /** Whether the contact needs a higher plan than the user's. */
    public function isLocked(User $user, Contact $contact): bool
    {
        $required = $contact->requiredPlan;

        return $required !== null && $this->entitlements->planFor($user)->sort < $required->sort;
    }

    public function entry(User $user, Contact $contact): ?UserContact
    {
        return UserContact::where('user_id', $user->id)->where('contact_id', $contact->id)->first();
    }

    public function check(User $user, Contact $contact): AddCheck
    {
        return match (true) {
            $this->entry($user, $contact) !== null => AddCheck::AlreadyAdded,
            ! $contact->is_published || $contact->trashed() => AddCheck::Unavailable,
            $this->isLocked($user, $contact) => AddCheck::Locked,
            $this->limit($user) !== null && $this->count($user) >= $this->limit($user) => AddCheck::LimitReached,
            default => AddCheck::Ok,
        };
    }

    /**
     * Puts the contact on the user's phone as who they are to the user (Friend if not chosen), bringing back their
     * earlier entry if they had one.
     *
     * @throws DomainException with the reason when they can't.
     */
    public function add(User $user, Contact $contact, ?Relationship $relationship = null): UserContact
    {
        $relationship ??= Relationship::bySlug('friend');
        $this->ensureSuits($relationship, $contact);

        return DB::transaction(function () use ($user, $contact, $relationship) {
            // One add at a time per user, so two at once can't both squeeze under the limit.
            User::whereKey($user->id)->lockForUpdate()->first();

            $check = $this->check($user, $contact);
            if ($check !== AddCheck::Ok) {
                throw new DomainException($check->message());
            }

            $earlier = UserContact::onlyTrashed()->where('user_id', $user->id)->where('contact_id', $contact->id)->first();
            if ($earlier) {
                $earlier->restore();
                $earlier->update(['relationship_id' => $relationship->id]);

                return $earlier;
            }

            return UserContact::create([
                'user_id' => $user->id, 'contact_id' => $contact->id, 'relationship_id' => $relationship->id, 'added_at' => now(),
            ]);
        });
    }

    /**
     * Changes who the contact is to the user.
     *
     * @throws DomainException when that relationship doesn't suit the contact.
     */
    public function setRelationship(UserContact $entry, Relationship $relationship): UserContact
    {
        $this->ensureSuits($relationship, $entry->contact);
        $entry->update(['relationship_id' => $relationship->id]);

        return $entry->setRelation('relationship', $relationship);
    }

    private function ensureSuits(Relationship $relationship, Contact $contact): void
    {
        if (! $relationship->suits($contact)) {
            throw new DomainException("{$contact->firstName()} can't be your ".strtolower($relationship->label).'.');
        }
    }

    /**
     * Takes the contact off their phone. Nothing is deleted: adding them again brings it all back.
     */
    public function remove(User $user, Contact $contact): void
    {
        $this->entry($user, $contact)?->delete();
    }
}
