<?php

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';
    public string $date_of_birth = '';
    public string $timezone = 'UTC'; // filled in from the browser
    public bool $terms = false;

    /**
     * Handle an incoming registration request.
     */
    public function register(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:' . User::class],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
            'date_of_birth' => User::birthDateRules(),
            'timezone' => User::timezoneRules(),
            'terms' => ['accepted'],
        ], [
            'date_of_birth.before_or_equal' => __('Call Joe is only for adults (18 and over).'),
            'terms.accepted' => __('Please agree to the Terms and Privacy Policy to continue.'),
        ]);
        unset($validated['terms']);

        $validated['password'] = Hash::make($validated['password']);

        $user = User::create($validated);
        $user->acceptTerms();
        event(new Registered($user));

        Auth::login($user);

        $this->redirect(route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header title="Create an account" description="Enter your details below to create your account" />

    <!-- Session Status -->
    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="register" class="flex flex-col gap-6">
        <!-- Name -->
        <div class="grid gap-2">
            <flux:input wire:model="name" id="name" label="{{ __('Name') }}" type="text" name="name" required autofocus autocomplete="name" placeholder="Full name" />
        </div>

        <!-- Email Address -->
        <div class="grid gap-2">
            <flux:input wire:model="email" id="email" label="{{ __('Email address') }}" type="email" name="email" required autocomplete="email" placeholder="email@example.com" />
        </div>

        <!-- Date of birth (adults only) -->
        <div class="grid gap-2">
            <flux:input wire:model="date_of_birth" id="date_of_birth" label="{{ __('Date of birth') }}" type="date" name="date_of_birth" required autocomplete="bday" :max="now()->subYears(\App\Models\User::MINIMUM_AGE)->toDateString()" description="{{ __('Call Joe is for adults (18 and over).') }}" />
        </div>

        <!-- Their timezone, so contacts know their time of day -->
        <input type="hidden" wire:model="timezone" x-data x-init="$wire.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'" />

        <!-- Password -->
        <div class="grid gap-2">
            <flux:input
                wire:model="password"
                id="password"
                label="{{ __('Password') }}"
                type="password"
                name="password"
                required
                autocomplete="new-password"
                placeholder="Password"
            />
        </div>

        <!-- Confirm Password -->
        <div class="grid gap-2">
            <flux:input
                wire:model="password_confirmation"
                id="password_confirmation"
                label="{{ __('Confirm password') }}"
                type="password"
                name="password_confirmation"
                required
                autocomplete="new-password"
                placeholder="Confirm password"
            />
        </div>

        <!-- The Terms, Privacy Policy and AI disclosure -->
        <div class="grid gap-2">
            <flux:checkbox wire:model="terms" id="terms" name="terms" required :label="__('I agree to the Terms and Privacy Policy, and I understand my contacts are AI, not real people.')" />
            <x-legal-links />
            <flux:error name="terms" />
        </div>

        <div class="flex items-center justify-end">
            <flux:button type="submit" variant="primary" class="w-full">
                {{ __('Create account') }}
            </flux:button>
        </div>
    </form>

    <div class="space-x-1 text-center text-sm text-zinc-600 dark:text-zinc-400">
        Already have an account?
        <x-text-link href="{{ route('login') }}">Log in</x-text-link>
    </div>
</div>
