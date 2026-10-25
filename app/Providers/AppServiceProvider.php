<?php

namespace App\Providers;

use App\Contracts\LlmClient;
use App\Enums\Feature;
use App\Models\User;
use App\Services\Entitlements;
use App\Support\Llm\GroqClient;
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

        // The AI provider. Tests swap in App\Support\Llm\FakeLlmClient (tests/TestCase.php).
        $this->app->singleton(LlmClient::class, fn () => new GroqClient(
            (string) config('services.groq.key'),
            (string) config('services.groq.base_url'),
            (int) config('services.groq.timeout'),
        ));
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
