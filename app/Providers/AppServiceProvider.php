<?php

namespace App\Providers;

use App\Contracts\LlmClient;
use App\Contracts\VoiceProvider;
use App\Enums\Feature;
use App\Models\User;
use App\Services\Entitlements;
use App\Support\Llm\GroqClient;
use App\Support\Voice\VapiClient;
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

        // Voice calls. Tests swap in App\Support\Voice\FakeVoiceProvider (tests/TestCase.php).
        $this->app->singleton(VoiceProvider::class, fn () => new VapiClient(
            (string) config('services.vapi.private_key'),
            (string) config('services.vapi.public_key'),
            (string) config('services.vapi.base_url'),
        ));

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
