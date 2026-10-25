<?php

namespace App\Enums;

/**
 * Whether a user can add a contact to their phone, and if not, why.
 */
enum AddCheck: string
{
    case Ok = 'ok';
    case AlreadyAdded = 'already_added';
    case Locked = 'locked';
    case LimitReached = 'limit_reached';
    case Unavailable = 'unavailable';

    public function message(): string
    {
        return match ($this) {
            self::Ok => 'You can add them.',
            self::AlreadyAdded => 'They\'re already on your phone.',
            self::Locked => 'They\'re part of a paid plan.',
            self::LimitReached => 'Your plan\'s contacts are full. Remove one, or upgrade for more.',
            self::Unavailable => 'They aren\'t available right now.',
        };
    }
}
