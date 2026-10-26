<?php

namespace Tests;

use App\Contracts\ImageGenerator;
use App\Contracts\LlmClient;
use App\Contracts\VoiceProvider;
use App\Services\LlmProviders;
use App\Support\Images\FakeImageGenerator;
use App\Support\Llm\FakeLlmClient;
use App\Support\Voice\FakeVoiceProvider;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected FakeLlmClient $llm;

    protected FakeVoiceProvider $voice;

    protected FakeImageGenerator $images;

    protected function setUp(): void
    {
        parent::setUp();

        // Tests never reach a real outside service; each one is faked (Http::fake() or a fake implementation).
        Http::preventStrayRequests();
        $this->llm = new FakeLlmClient;
        $this->app->instance(LlmClient::class, $this->llm);
        $this->app->make(LlmProviders::class)->fake($this->llm); // one fake answers Claude, Gemini, Grok and Groq alike
        $this->voice = new FakeVoiceProvider;
        $this->app->instance(VoiceProvider::class, $this->voice);
        $this->images = new FakeImageGenerator;
        $this->app->instance(ImageGenerator::class, $this->images);
    }
}
