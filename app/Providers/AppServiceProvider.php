<?php

namespace App\Providers;

use App\Contracts\ImageGenerator;
use App\Contracts\PaymentGateway;
use App\Contracts\VoiceProvider;
use App\Enums\Feature;
use App\Http\Middleware\EnsureNotSuspended;
use App\Http\Middleware\EnsureTermsAccepted;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\LlmProviders;
use App\Support\Billing\BraintreeClient;
use App\Support\Images\XaiImageClient;
use App\Support\Voice\PipecatClient;
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

        // Payments. Tests swap in App\Support\Billing\FakePaymentGateway (tests/TestCase.php).
        $this->app->singleton(PaymentGateway::class, fn () => new BraintreeClient([
            'environment' => (string) config('services.braintree.environment'),
            'merchantId' => (string) config('services.braintree.merchant_id'),
            'publicKey' => (string) config('services.braintree.public_key'),
            'privateKey' => (string) config('services.braintree.private_key'),
        ]));

        // Voice calls. Tests swap in App\Support\Voice\FakeVoiceProvider (tests/TestCase.php).
        $this->app->singleton(VoiceProvider::class, fn () => new PipecatClient(
            (string) config('services.voice.url'),
            (string) config('services.voice.public_url'),
            (string) config('services.voice.secret'),
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
