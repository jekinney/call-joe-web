<?php

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Livewire\Volt\Volt;

// Admin access is a role: a named set of permissions. Regular users have no role (their tier is their plan).

it('starts with an Owner role plus Support and Content editor starters', function () {
    expect(Role::where('is_owner', true)->sole()->name)->toBe('Owner')
        ->and(Role::where('name', 'Support')->sole()->permissions)->toContain('users.view', 'users.plan', 'safety.review')
        ->and(Role::where('name', 'Content editor')->sole()->permissions)->toContain('contacts.manage');
});

it('gives the owner every permission and a regular user none', function () {
    $owner = User::factory()->admin()->create();
    $regular = User::factory()->create();

    foreach (Permission::cases() as $permission) {
        expect($owner->hasPermission($permission))->toBeTrue()
            ->and($regular->hasPermission($permission))->toBeFalse();
    }

    expect($owner->is_admin)->toBeTrue()->and($owner->isOwner())->toBeTrue()
        ->and($regular->is_admin)->toBeFalse()->and($regular->isOwner())->toBeFalse();
});

it('only opens the pages a role allows', function () {
    $support = User::factory()->allowing(Permission::UsersView)->create();

    $this->actingAs($support)->get(route('admin.users.index'))->assertOk();
    $this->actingAs($support)->get(route('admin.models'))->assertForbidden();
    $this->actingAs($support)->get(route('admin.roles'))->assertForbidden();
    $this->actingAs($support)->get(route('admin.home'))->assertForbidden();
});

it('shows only the menu items the role allows', function () {
    $this->actingAs(User::factory()->allowing(Permission::UsersView, Permission::SafetyReview)->create())
        ->get(route('admin.users.index'))
        ->assertSee(route('admin.moderation'))
        ->assertDontSee(route('admin.models'))
        ->assertDontSee(route('admin.roles'));

    $this->actingAs(User::factory()->admin()->create())->get(route('admin.home'))
        ->assertSee(route('admin.models'))->assertSee(route('admin.roles'));
});

it('sends an admin home to the first page their role opens', function () {
    $this->actingAs(User::factory()->admin()->create())->get(route('dashboard'))->assertRedirect(route('admin.home'));
    $this->actingAs(User::factory()->allowing(Permission::ModelsManage)->create())->get(route('dashboard'))->assertRedirect(route('admin.models'));
    $this->actingAs(User::factory()->allowing()->create())->get(route('dashboard'))->assertRedirect(route('app.home'));
    $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertRedirect(route('app.home'));
});

it('lets a role with only view access look but not change a plan or suspend', function () {
    $viewer = User::factory()->allowing(Permission::UsersView)->create();
    $target = User::factory()->create();

    Volt::actingAs($viewer)->test('admin.users.show', ['user' => $target])
        ->assertDontSee('Save plan')
        ->call('savePlan')->assertForbidden();
    Volt::actingAs($viewer)->test('admin.users.show', ['user' => $target])
        ->set('suspendReason', 'x')->call('suspend')->assertForbidden();
    Volt::actingAs($viewer)->test('admin.users.show', ['user' => $target])
        ->call('unsuspend')->assertForbidden();
});

it('checks the permission on every admin action, not only on the page', function () {
    $nobody = User::factory()->allowing(Permission::UsersView)->create();

    Volt::actingAs($nobody)->test('admin.models')->call('create')->assertForbidden();
    Volt::actingAs($nobody)->test('admin.pictures')->call('purge', 1)->assertForbidden();
    Volt::actingAs($nobody)->test('admin.moderation')->call('review', 1)->assertForbidden();
    Volt::actingAs(User::factory()->allowing(Permission::SafetyReview)->create())->test('admin.moderation')->call('suspend', 1)->assertForbidden();
    Volt::actingAs($nobody)->test('admin.roles')->call('create')->assertForbidden();
});

describe('Roles screen', function () {
    beforeEach(function () {
        $this->owner = User::factory()->admin()->create();
    });

    it('lists roles, with the owner locked', function () {
        Volt::actingAs($this->owner)->test('admin.roles')
            ->assertSee('Owner')->assertSee('Everything')->assertSee('Support')->assertSee('Content editor');
    });

    it('creates a role with chosen permissions', function () {
        Volt::actingAs($this->owner)->test('admin.roles')
            ->call('create')
            ->set('name', 'Billing')
            ->set('description', 'Money questions')
            ->set('selected', ['overview.view', 'users.view'])
            ->call('save')
            ->assertHasNoErrors();

        $role = Role::where('name', 'Billing')->sole();
        expect($role->permissions)->toBe(['overview.view', 'users.view'])->and($role->description)->toBe('Money questions')->and($role->is_owner)->toBeFalse();
    });

    it('edits a role', function () {
        $role = Role::factory()->allowing(Permission::UsersView)->create(['name' => 'Helper']);

        Volt::actingAs($this->owner)->test('admin.roles')
            ->call('edit', $role->id)
            ->assertSet('name', 'Helper')->assertSet('selected', ['users.view'])
            ->set('name', 'Helpers')->set('selected', ['users.view', 'users.plan'])
            ->call('save');

        expect($role->refresh()->name)->toBe('Helpers')->and($role->permissions)->toBe(['users.view', 'users.plan']);
    });

    it('needs a unique name and real permissions', function () {
        Volt::actingAs($this->owner)->test('admin.roles')
            ->call('create')
            ->set('name', 'Support')->set('selected', ['fly.planes'])
            ->call('save')
            ->assertHasErrors(['name', 'selected.0']);
    });

    it('never changes or deletes the Owner role', function () {
        $owner = Role::owner();

        Volt::actingAs($this->owner)->test('admin.roles')->call('edit', $owner->id)->assertForbidden();
        Volt::actingAs($this->owner)->test('admin.roles')->call('delete', $owner->id)->assertForbidden();
    });

    it('refuses to save over the Owner role', function () {
        Volt::actingAs($this->owner)->test('admin.roles')
            ->set('editingId', Role::owner()->id)->set('name', 'Hacked')
            ->call('save')->assertForbidden();

        expect(Role::owner()->name)->toBe('Owner');
    });

    it('deletes an unused role but not one that admins hold', function () {
        $unused = Role::factory()->create(['name' => 'Unused']);
        $used = Role::factory()->create(['name' => 'Used']);
        User::factory()->create()->forceFill(['role_id' => $used->id])->save();

        Volt::actingAs($this->owner)->test('admin.roles')
            ->call('delete', $used->id)->assertHasErrors('delete')
            ->call('delete', $unused->id)->assertHasNoErrors();

        expect(Role::where('name', 'Unused')->exists())->toBeFalse()->and(Role::where('name', 'Used')->exists())->toBeTrue();
    });

    it('does not let anyone hand out a permission they lack', function () {
        $manager = User::factory()->allowing(Permission::RolesManage, Permission::UsersView)->create();
        $role = Role::factory()->allowing(Permission::ModelsManage)->create(['name' => 'Tuners']);

        Volt::actingAs($manager)->test('admin.roles')
            ->call('edit', $role->id)
            ->set('selected', ['users.view', 'users.plan', 'overview.view'])
            ->call('save');

        // users.view was theirs to give; models.manage stays untouched; the rest are dropped.
        expect($role->refresh()->permissions)->toEqualCanonicalizing(['models.manage', 'users.view']);
    });
});

describe('Giving a role to a user', function () {
    beforeEach(function () {
        $this->owner = User::factory()->admin()->create();
        $this->target = User::factory()->create();
        $this->support = Role::where('name', 'Support')->sole();
    });

    it('makes a user an admin with a role, and takes it away again', function () {
        Volt::actingAs($this->owner)->test('admin.users.show', ['user' => $this->target])
            ->set('roleId', $this->support->id)->call('saveRole')->assertHasNoErrors();
        expect($this->target->refresh()->role_id)->toBe($this->support->id)->and($this->target->is_admin)->toBeTrue();

        Volt::actingAs($this->owner)->test('admin.users.show', ['user' => $this->target])
            ->set('roleId', null)->call('saveRole');
        expect($this->target->refresh()->role_id)->toBeNull()->and($this->target->is_admin)->toBeFalse();
    });

    it('never hands out the Owner role through the website', function () {
        Volt::actingAs($this->owner)->test('admin.users.show', ['user' => $this->target])
            ->set('roleId', Role::owner()->id)->call('saveRole')->assertHasErrors('roleId');

        expect($this->target->refresh()->role_id)->toBeNull();
    });

    it('does not let an admin change their own role or an owner\'s', function () {
        $manager = User::factory()->allowing(Permission::RolesManage)->create();
        $otherOwner = User::factory()->admin()->create();

        Volt::actingAs($manager)->test('admin.users.show', ['user' => $manager])
            ->set('roleId', null)->call('saveRole')->assertStatus(422);
        Volt::actingAs($manager)->test('admin.users.show', ['user' => $otherOwner])
            ->set('roleId', null)->call('saveRole')->assertForbidden();

        expect($manager->refresh()->is_admin)->toBeTrue()->and($otherOwner->refresh()->isOwner())->toBeTrue();
    });

    it('does not let someone give a role that allows more than they have', function () {
        $manager = User::factory()->allowing(Permission::RolesManage, Permission::UsersView)->create();

        Volt::actingAs($manager)->test('admin.users.show', ['user' => $this->target])
            ->set('roleId', $this->support->id)->call('saveRole')->assertHasErrors('roleId');

        expect($this->target->refresh()->role_id)->toBeNull();
    });

    it('is for people who manage roles only', function () {
        Volt::actingAs(User::factory()->allowing(Permission::UsersView)->create())->test('admin.users.show', ['user' => $this->target])
            ->assertDontSee('Admin role')->call('saveRole')->assertForbidden();
    });

    it('shows the role next to the name in the users list', function () {
        $this->target->forceFill(['role_id' => $this->support->id])->save();

        Volt::actingAs($this->owner)->test('admin.users.index')->assertSee('Support');
    });

    it('explains an owner and your own role instead of offering a picker', function () {
        Volt::actingAs($this->owner)->test('admin.users.show', ['user' => $this->owner])
            ->assertSee('Owner. Changed on the command line only.');

        $manager = User::factory()->allowing(Permission::RolesManage)->create();
        Volt::actingAs($manager)->test('admin.users.show', ['user' => $manager])
            ->assertSee('You can\'t change your own role.');
    });
});

describe('Command line', function () {
    it('gives a named role', function () {
        $user = User::factory()->create(['email' => 'help@example.com']);

        $this->artisan('app:make-admin', ['email' => 'help@example.com', '--role' => 'Support'])
            ->expectsOutput('help@example.com is now an admin with the Support role.')
            ->assertSuccessful();

        expect($user->refresh()->role->name)->toBe('Support');
    });

    it('lists the roles when the name is wrong', function () {
        User::factory()->create(['email' => 'help@example.com']);

        $this->artisan('app:make-admin', ['email' => 'help@example.com', '--role' => 'Wizard'])
            ->expectsOutputToContain('No role called Wizard. Roles: Content editor, Owner, Support.')
            ->assertFailed();
    });
});

it('turns everyone who was an admin into an Owner when the migration runs', function () {
    $migration = require database_path('migrations/2026_10_27_100000_create_roles_and_drop_is_admin.php');

    $migration->down();
    expect(Schema::hasColumn('users', 'is_admin'))->toBeTrue()->and(Schema::hasTable('roles'))->toBeFalse();

    DB::table('users')->insert([
        ['name' => 'Boss', 'email' => 'boss@example.com', 'password' => 'x', 'is_admin' => true, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'Pat', 'email' => 'pat@example.com', 'password' => 'x', 'is_admin' => false, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $migration->up();

    $boss = User::where('email', 'boss@example.com')->sole();
    expect(Schema::hasColumn('users', 'is_admin'))->toBeFalse()
        ->and($boss->isOwner())->toBeTrue()
        ->and(User::where('email', 'pat@example.com')->sole()->role_id)->toBeNull();
});
