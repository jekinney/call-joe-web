<?php

namespace Tests;

use App\Contracts\LlmClient;
use App\Support\Llm\FakeLlmClient;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected FakeLlmClient $llm;

    protected function setUp(): void
    {
        parent::setUp();

        // Tests never reach a real outside service; each one is faked (Http::fake() or a fake implementation).
        Http::preventStrayRequests();
        $this->llm = new FakeLlmClient;
        $this->app->instance(LlmClient::class, $this->llm);
    }
}
