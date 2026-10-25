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
});

require __DIR__.'/auth.php';
