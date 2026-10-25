<?php

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Livewire\Volt\Volt;

// Registration only for adults, with a real birthday and timezone.

function register(array $overrides = [])
{
    $form = array_merge([
        'name' => 'Jamie Rivera',
        'email' => 'jamie@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'date_of_birth' => now()->subYears(30)->toDateString(),
        'timezone' => 'America/Chicago',
    ], $overrides);

    $component = Volt::test('auth.register');
    foreach ($form as $field => $value) {
        $component->set($field, $value);
    }

    return $component->call('register');
}

it('registers an adult and saves their birthday and timezone', function () {
    Event::fake([Registered::class]);

    register()->assertHasNoErrors()->assertRedirect(route('dashboard', absolute: false));

    $user = User::firstWhere('email', 'jamie@example.com');
    expect($user->date_of_birth->toDateString())->toBe(now()->subYears(30)->toDateString())
        ->and($user->timezone)->toBe('America/Chicago')
        ->and($user->is_admin)->toBeFalse()
        ->and($user->hasVerifiedEmail())->toBeFalse();
    Event::assertDispatched(Registered::class);
});

it('accepts someone who turns 18 today', function () {
    register(['date_of_birth' => now()->subYears(18)->toDateString()])->assertHasNoErrors();
});

it('turns away anyone under 18, with a clear message', function () {
    register(['date_of_birth' => now()->subYears(18)->addDay()->toDateString()])
        ->assertHasErrors(['date_of_birth' => 'before_or_equal'])
        ->assertSee('Call Joe is only for adults (18 and over).');

    expect(User::count())->toBe(0);
});

it('rejects a missing, impossible or badly formatted birthday', function (string $dob, string $rule) {
    register(['date_of_birth' => $dob])->assertHasErrors(['date_of_birth' => $rule]);
})->with([
    'missing' => ['', 'required'],
    'not a date' => ['someday', 'date_format'],
    'wrong format' => ['03/04/1990', 'date_format'],
    'over 120 years ago' => ['1850-01-01', 'after'],
]);

it('rejects a timezone that does not exist', function () {
    register(['timezone' => 'Mars/Olympus_Mons'])->assertHasErrors(['timezone' => 'timezone']);
});

it('rejects an email that is already taken', function () {
    User::factory()->create(['email' => 'jamie@example.com']);

    register()->assertHasErrors(['email' => 'unique']);
});
