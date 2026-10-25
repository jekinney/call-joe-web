<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\PresenceController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

// "Home" after logging in: admins to /admin, everyone else to /app.
Route::get('home', HomeController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

// The phone: signed in, email verified, and an adult.
Route::prefix('app')->name('app.')->middleware(['auth', 'verified', 'adult'])->group(function () {
    Route::view('/', 'app.home')->name('home');
    Route::post('presence', PresenceController::class)->middleware('throttle:10,1')->name('presence');
    Volt::route('plan', 'app.plan')->name('plan');
    Volt::route('catalog', 'app.catalog')->name('catalog');
    Volt::route('catalog/{contact:slug}', 'app.catalog.show')->name('catalog.show');
    Volt::route('contacts', 'app.contacts')->name('contacts');
    Volt::route('chat/{contact:slug}', 'app.chat')->name('chat');
});

// Settings stay reachable before verifying, so a mistyped email can be fixed.
Route::prefix('app/settings')->middleware(['auth'])->group(function () {
    Route::redirect('/', '/app/settings/profile');

    Volt::route('profile', 'settings.profile')->name('settings.profile');
    Volt::route('password', 'settings.password')->name('settings.password');
    Volt::route('appearance', 'settings.appearance')->name('settings.appearance');
});

// Running the service: admins only.
Route::prefix('admin')->name('admin.')->middleware(['auth', 'verified', 'can:admin'])->group(function () {
    Route::view('/', 'admin.home')->name('home');
    Volt::route('users', 'admin.users.index')->name('users.index');
    Volt::route('users/{user}', 'admin.users.show')->name('users.show');
    Volt::route('contacts', 'admin.contacts.index')->name('contacts.index');
    Volt::route('contacts/new', 'admin.contacts.edit')->name('contacts.create');
    Volt::route('contacts/{contact}', 'admin.contacts.edit')->name('contacts.edit');
    Volt::route('prompt-preview', 'admin.prompt-preview')->name('prompt-preview');
    Volt::route('models', 'admin.models')->name('models');
});

require __DIR__.'/auth.php';
