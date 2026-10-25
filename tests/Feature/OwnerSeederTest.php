<?php

use App\Models\User;
use Database\Seeders\OwnerSeeder;
use Illuminate\Support\Facades\Hash;

// The owner's admin account comes from .env, never from code.

function owner(array $values): void
{
    config(['app.owner' => [...['name' => 'Pat Owner', 'email' => 'Pat@Example.com', 'password' => 'from-env', 'birth_date' => '1980-04-02', 'timezone' => 'America/Chicago'], ...$values]]);
}

it('creates the owner as a verified admin who has accepted the terms', function () {
    owner([]);

    $this->seed(OwnerSeeder::class);

    $pat = User::sole();
    expect($pat->only(['name', 'email', 'timezone', 'is_admin']))->toBe(['name' => 'Pat Owner', 'email' => 'pat@example.com', 'timezone' => 'America/Chicago', 'is_admin' => true])
        ->and(Hash::check('from-env', $pat->password))->toBeTrue()
        ->and($pat->hasVerifiedEmail())->toBeTrue()
        ->and($pat->hasAcceptedTerms())->toBeTrue()
        ->and($pat->date_of_birth->toDateString())->toBe('1980-04-02');
});

it('keeps an existing owner\'s password and birthday when run again', function () {
    $pat = User::factory()->create(['email' => 'pat@example.com', 'password' => Hash::make('changed-later')]);
    owner(['password' => 'from-env', 'birth_date' => null, 'name' => null, 'timezone' => null]);

    $this->seed(OwnerSeeder::class);

    $pat->refresh();
    expect($pat->is_admin)->toBeTrue()->and(Hash::check('changed-later', $pat->password))->toBeTrue()
        ->and($pat->name)->toBe('Owner')->and($pat->timezone)->toBe('UTC')->and($pat->date_of_birth)->not->toBeNull();
});

it('skips the owner when .env has no email or password', function (array $missing) {
    owner($missing);

    $this->artisan('db:seed', ['--class' => OwnerSeeder::class])->expectsOutputToContain('Skipped the owner account');

    expect(User::count())->toBe(0);
})->with([[['email' => null]], [['password' => '']]]);
