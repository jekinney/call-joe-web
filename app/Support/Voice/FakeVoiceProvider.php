<?php

namespace App\Support\Voice;

use App\Contracts\VoiceProvider;
use App\Exceptions\VoiceException;

/**
 * Stands in for the voice service in tests (bound in tests/TestCase.php): remembers what was created and deleted, and
 * can be told to fail.
 */
class FakeVoiceProvider implements VoiceProvider
{
    /** @var array<string, array<string, mixed>> created sessions, by id */
    public array $sessions = [];

    /** @var list<string> */
    public array $deleted = [];

    private bool $failing = false;

    public function fail(): static
    {
        $this->failing = true;

        return $this;
    }

    public function createSession(array $config): string
    {
        if ($this->failing) {
            throw new VoiceException('The fake voice service is down.');
        }

        $id = 'sess_fake_'.(count($this->sessions) + 1);
        $this->sessions[$id] = $config;

        return $id;
    }

    public function deleteSession(string $id): void
    {
        $this->deleted[] = $id;
    }

    public function connectUrl(string $sessionId): string
    {
        return "https://voice.test/api/offer?session={$sessionId}";
    }
}
