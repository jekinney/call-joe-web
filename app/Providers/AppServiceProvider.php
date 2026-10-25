<?php

namespace App\Providers;

use App\Enums\Feature;
use App\Models\User;
use App\Services\Entitlements;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Entitlements::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The /admin area.
        Gate::define('admin', fn (User $user): bool => $user->is_admin);

        // A plan feature, in Blade (@can('feature', App\Enums\Feature::Voicemail)) or code.
        Gate::define('feature', fn (User $user, Feature $feature): bool => app(Entitlements::class)->can($user, $feature));
    }
}
