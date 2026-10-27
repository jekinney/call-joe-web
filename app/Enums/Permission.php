<?php

namespace App\Enums;

/**
 * One thing an admin role can allow. Regular users have no role; their tier is their plan.
 */
enum Permission: string
{
    case OverviewView = 'overview.view';
    case UsersView = 'users.view';
    case UsersPlan = 'users.plan';
    case UsersSuspend = 'users.suspend';
    case ContactsManage = 'contacts.manage';
    case SafetyReview = 'safety.review';
    case PicturesManage = 'pictures.manage';
    case ModelsManage = 'models.manage';
    case PromptsView = 'prompts.view';
    case RolesManage = 'roles.manage';

    public function label(): string
    {
        return match ($this) {
            self::OverviewView => 'See the overview (revenue, usage, AI cost)',
            self::UsersView => 'See users and their usage',
            self::UsersPlan => 'Change a user\'s plan',
            self::UsersSuspend => 'Suspend and unsuspend users',
            self::ContactsManage => 'Create, edit and publish contacts',
            self::SafetyReview => 'Review blocked texts',
            self::PicturesManage => 'Manage the picture cache',
            self::ModelsManage => 'Manage AI models and keys',
            self::PromptsView => 'Use the prompt preview',
            self::RolesManage => 'Manage roles and give them to users',
        };
    }

    /** The heading it sits under on the Roles screen. */
    public function group(): string
    {
        return match ($this) {
            self::OverviewView => 'Overview',
            self::UsersView, self::UsersPlan, self::UsersSuspend => 'Users',
            self::ContactsManage, self::PromptsView => 'Contacts',
            self::SafetyReview => 'Safety',
            self::PicturesManage => 'Pictures',
            self::ModelsManage => 'AI',
            self::RolesManage => 'Access',
        };
    }

    /** The admin page this opens, if it has one (plan and suspend changes happen on the user page). */
    public function page(): ?string
    {
        return match ($this) {
            self::OverviewView => 'admin.home',
            self::UsersView => 'admin.users.index',
            self::ContactsManage => 'admin.contacts.index',
            self::SafetyReview => 'admin.moderation',
            self::PicturesManage => 'admin.pictures',
            self::ModelsManage => 'admin.models',
            self::PromptsView => 'admin.prompt-preview',
            self::RolesManage => 'admin.roles',
            self::UsersPlan, self::UsersSuspend => null,
        };
    }

    /**
     * @return array<string, list<self>> grouped for the Roles screen
     */
    public static function grouped(): array
    {
        $groups = [];
        foreach (self::cases() as $permission) {
            $groups[$permission->group()][] = $permission;
        }

        return $groups;
    }
}
