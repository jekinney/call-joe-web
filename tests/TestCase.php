<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests never reach a real outside service; each one is faked (Http::fake() or a fake implementation).
        Http::preventStrayRequests();
    }
}
