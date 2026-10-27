<?php

use App\Http\Controllers\AdminPictureController;
use App\Http\Controllers\BraintreeWebhookController;
use App\Http\Controllers\DataExportController;
use App\Http\Controllers\DeclineCallController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\PresenceController;
use App\Http\Controllers\VoiceWebhookController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Volt::route('/', 'landing')->name('home');

// The voice service's call reports (checked by a secret header; no session or CSRF token).
Route::post('webhooks/voice', VoiceWebhookController::class)->middleware('throttle:120,1')->name('webhooks.voice');

// Braintree's payment webhooks (checked by Braintree's signature; no session or CSRF token).
Route::match(['get', 'post'], 'webhooks/braintree', BraintreeWebhookController::class)->middleware('throttle:120,1')->name('webhooks.braintree');

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
    Volt::route('data', 'settings.data')->name('settings.data');
    Route::get('data/export', DataExportController::class)->middleware('throttle:5,1')->name('settings.data.export');
});

// Running the service: admins only, and each page needs the permission from the admin's role (App\Enums\Permission).
Route::prefix('admin')->name('admin.')->middleware(['auth', 'verified', 'can:admin'])->group(function () {
    Volt::route('/', 'admin.home')->middleware('can:overview.view')->name('home');
    Volt::route('users', 'admin.users.index')->middleware('can:users.view')->name('users.index');
    Volt::route('users/{user}', 'admin.users.show')->middleware('can:users.view')->name('users.show');
    Volt::route('contacts', 'admin.contacts.index')->middleware('can:contacts.manage')->name('contacts.index');
    Volt::route('contacts/new', 'admin.contacts.edit')->middleware('can:contacts.manage')->name('contacts.create');
    Volt::route('contacts/{contact}', 'admin.contacts.edit')->middleware('can:contacts.manage')->name('contacts.edit');
    Volt::route('prompt-preview', 'admin.prompt-preview')->middleware('can:prompts.view')->name('prompt-preview');
    Volt::route('models', 'admin.models')->middleware('can:models.manage')->name('models');
    Volt::route('moderation', 'admin.moderation')->middleware('can:safety.review')->name('moderation');
    Volt::route('pictures', 'admin.pictures')->middleware('can:pictures.manage')->name('pictures');
    Route::get('pictures/file/{image}', AdminPictureController::class)->middleware('can:pictures.manage')->name('pictures.file');
    Volt::route('pictures/{contactId}', 'admin.pictures.contact')->whereNumber('contactId')->middleware('can:pictures.manage')->name('pictures.contact');
    Volt::route('roles', 'admin.roles')->middleware('can:roles.manage')->name('roles');
});

require __DIR__.'/auth.php';
