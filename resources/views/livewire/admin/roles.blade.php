<?php

use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('components.layouts.admin'), Title('Roles')] class extends Component {
    public ?int $editingId = null;

    public bool $showForm = false;

    public string $name = '';

    public string $description = '';

    /** @var list<string> */
    public array $selected = [];

    public function create(): void
    {
        Gate::authorize('roles.manage');
        $this->reset('editingId', 'name', 'description', 'selected');
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        Gate::authorize('roles.manage');
        $role = Role::findOrFail($id);
        abort_if($role->is_owner, 403, 'The Owner role can\'t be changed.');

        $this->editingId = $role->id;
        $this->name = $role->name;
        $this->description = (string) $role->description;
        $this->selected = $role->permissions;
        $this->resetValidation();
        $this->showForm = true;
    }

    /**
     * Save the role. Nobody can hand out a permission they don't have themselves, so a role never outgrows its maker;
     * permissions the editor lacks stay as they were.
     */
    public function save(): void
    {
        Gate::authorize('roles.manage');

        $this->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('roles', 'name')->ignore($this->editingId)],
            'description' => ['nullable', 'string', 'max:200'],
            'selected' => ['array'],
            'selected.*' => [Rule::enum(Permission::class)],
        ]);

        $role = $this->editingId ? Role::findOrFail($this->editingId) : new Role;
        abort_if($role->is_owner, 403, 'The Owner role can\'t be changed.');

        $mine = $this->myPermissions();
        $kept = array_values(array_diff($role->permissions, $mine));
        $granted = array_values(array_intersect($this->selected, $mine));

        $role->fill(['name' => $this->name, 'description' => $this->description ?: null, 'permissions' => array_values(array_unique([...$kept, ...$granted]))])->save();

        $this->showForm = false;
        $this->reset('editingId', 'name', 'description', 'selected');
    }

    public function cancel(): void
    {
        $this->showForm = false;
    }

    public function delete(int $id): void
    {
        Gate::authorize('roles.manage');
        $role = Role::withCount('users')->findOrFail($id);
        abort_if($role->is_owner, 403, 'The Owner role can\'t be deleted.');

        if ($role->users_count > 0) {
            $this->addError('delete', __('Take :name off its :count admin(s) first.', ['name' => $role->name, 'count' => $role->users_count]));

            return;
        }

        $role->delete();
    }

    /** @return list<string> */
    private function myPermissions(): array
    {
        return array_values(array_map(
            fn (Permission $permission) => $permission->value,
            array_filter(Permission::cases(), fn (Permission $permission) => auth()->user()->hasPermission($permission)),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'roles' => Role::withCount('users')->orderByDesc('is_owner')->orderBy('name')->get(),
            'groups' => Permission::grouped(),
            'mine' => $this->myPermissions(),
        ];
    }
}; ?>

<div class="flex w-full max-w-4xl flex-col gap-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Roles') }}</flux:heading>
            <flux:text>{{ __('A role is a set of admin permissions. Regular users have no role; their tier is their plan. Give a role to someone on their user page.') }}</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New role') }}</flux:button>
    </div>

    @error('delete')
        <flux:callout color="red" icon="exclamation-triangle" :heading="$message" />
    @enderror

    @if ($showForm)
        <form wire:submit="save" class="flex flex-col gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-role-form>
            <flux:heading size="lg">{{ $editingId ? __('Edit role') : __('New role') }}</flux:heading>
            <flux:input wire:model="name" :label="__('Name')" required />
            <flux:input wire:model="description" :label="__('What it is for (optional)')" />

            <flux:checkbox.group wire:model="selected" :label="__('Permissions')" class="flex flex-col gap-4">
                @foreach ($groups as $group => $permissions)
                    <div class="flex flex-col gap-2">
                        <flux:text size="sm" class="font-medium">{{ __($group) }}</flux:text>
                        @foreach ($permissions as $permission)
                            <flux:checkbox :value="$permission->value" :label="__($permission->label())" :disabled="! in_array($permission->value, $mine, true)" />
                        @endforeach
                    </div>
                @endforeach
            </flux:checkbox.group>
            <flux:text size="xs">{{ __('You can only give permissions you have yourself.') }}</flux:text>

            <div class="flex gap-3">
                <flux:button type="submit" variant="primary">{{ __('Save role') }}</flux:button>
                <flux:button type="button" variant="ghost" wire:click="cancel">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @endif

    <div class="flex flex-col gap-3">
        @foreach ($roles as $role)
            <div class="flex items-start justify-between gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-role="{{ $role->name }}">
                <div class="flex flex-col gap-1">
                    <div class="flex items-center gap-2">
                        <flux:heading>{{ $role->name }}</flux:heading>
                        @if ($role->is_owner)
                            <flux:badge color="amber" size="sm">{{ __('Everything') }}</flux:badge>
                        @endif
                        <flux:text size="sm">{{ trans_choice(':count admin|:count admins', $role->users_count, ['count' => $role->users_count]) }}</flux:text>
                    </div>
                    @if ($role->description)
                        <flux:text size="sm">{{ $role->description }}</flux:text>
                    @endif
                    @unless ($role->is_owner)
                        <flux:text size="xs">
                            {{ collect($role->permissions)->map(fn ($value) => Permission::tryFrom($value)?->label())->filter()->map(fn ($label) => __($label))->join(' · ') ?: __('No permissions yet.') }}
                        </flux:text>
                    @endunless
                </div>
                @unless ($role->is_owner)
                    <div class="flex shrink-0 gap-2">
                        <flux:button size="sm" wire:click="edit({{ $role->id }})">{{ __('Edit') }}</flux:button>
                        <flux:button size="sm" variant="danger" wire:click="delete({{ $role->id }})" wire:confirm="{{ __('Delete the :name role?', ['name' => $role->name]) }}">{{ __('Delete') }}</flux:button>
                    </div>
                @endunless
            </div>
        @endforeach
    </div>
</div>
