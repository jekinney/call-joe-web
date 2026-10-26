<?php

use App\Http\Controllers\BraintreeWebhookController;
use App\Http\Controllers\DeclineCallController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\PresenceController;
use App\Http\Controllers\VoiceWebhookController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

// The voice service's call reports (checked by a secret header; no session or CSRF token).
Route::post('webhooks/voice', VoiceWebhookController::class)->name('webhooks.voice');

// Braintree's payment webhooks (checked by Braintree's signature; no session or CSRF token).
Route::match(['get', 'post'], 'webhooks/braintree', BraintreeWebhookController::class)->name('webhooks.braintree');

// Shown by the service worker when there's no connection (cached when the app is installed).
Route::view('offline', 'offline')->name('offline');

// The Terms, Privacy Policy and AI disclosure (accepted at sign-up, and again when they change).
Route::view('terms', 'legal.terms')->name('legal.terms');
Route::view('privacy', 'legal.privacy')->name('legal.privacy');
Route::view('ai', 'legal.ai')->name('legal.ai');

// "Home" after logging in: admins to /admin, everyone else to /app.
Route::get('home', HomeController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

// Accepting updated terms, before going back into the app.
Volt::route('app/terms', 'app.terms')->middleware(['auth', 'verified', 'adult', 'not-suspended'])->name('terms.accept');

// The phone: signed in, email verified, an adult, not suspended, and the current terms accepted.
Route::prefix('app')->name('app.')->middleware(['auth', 'verified', 'adult', 'not-suspended', 'terms'])->group(function () {
    Route::view('/', 'app.home')->name('home');
    Route::post('presence', PresenceController::class)->middleware('throttle:10,1')->name('presence');
    Volt::route('plan', 'app.plan')->name('plan');
    Volt::route('catalog', 'app.catalog')->name('catalog');
    Volt::route('catalog/{contact:slug}', 'app.catalog.show')->name('catalog.show');
    Volt::route('contacts', 'app.contacts')->name('contacts');
    Volt::route('chat/{contact:slug}', 'app.chat')->name('chat');
    Volt::route('memory/{contact:slug}', 'app.memory')->name('memory');
    Volt::route('call/{contact:slug}', 'app.call')->name('call');
    Route::post('calls/{call}/decline', DeclineCallController::class)->name('calls.decline');
    Route::get('images/{image}', ImageController::class)->name('images.show');
});

// Settings stay reachable before verifying, so a mistyped email can be fixed.
Route::prefix('app/settings')->middleware(['auth'])->group(function () {
    Route::redirect('/', '/app/settings/profile');

    Volt::route('profile', 'settings.profile')->name('settings.profile');
    Volt::route('password', 'settings.password')->name('settings.password');
    Volt::route('appearance', 'settings.appearance')->name('settings.appearance');
    Volt::route('away', 'settings.away')->name('settings.away');
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
    Volt::route('moderation', 'admin.moderation')->name('moderation');
    Volt::route('pictures', 'admin.pictures')->name('pictures');
});

require __DIR__.'/auth.php';
