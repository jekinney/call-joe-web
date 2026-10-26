<?php

namespace App\Providers;

use App\Contracts\ImageGenerator;
use App\Contracts\VoiceProvider;
use App\Enums\Feature;
use App\Http\Middleware\EnsureNotSuspended;
use App\Http\Middleware\EnsureTermsAccepted;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\LlmProviders;
use App\Support\Images\XaiImageClient;
use App\Support\Voice\VapiClient;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

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

        // Pictures, from Grok Imagine (the same xAI key as Grok's texting). Tests swap in
        // App\Support\Images\FakeImageGenerator (tests/TestCase.php).
        $this->app->singleton(ImageGenerator::class, fn () => new XaiImageClient(
            (string) config('services.xai.key'),
            (string) config('services.xai.base_url'),
            (string) config('services.xai.image_model'),
            (int) config('services.xai.image_timeout'),
        ));

        // The AI providers (Claude, Gemini, Grok, Groq), each with its own key. Tests answer all of them with
        // App\Support\Llm\FakeLlmClient (tests/TestCase.php).
        $this->app->singleton(LlmProviders::class);
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

        // Livewire's own requests (a button on a page) check these too, not only the first page load.
        Livewire::addPersistentMiddleware([EnsureNotSuspended::class, EnsureTermsAccepted::class]);
    }
}
