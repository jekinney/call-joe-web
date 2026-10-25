<?php

namespace App\Support\Voice;

use App\Contracts\VoiceProvider;
use App\Exceptions\VoiceException;

/**
 * Stands in for Vapi in tests (bound in tests/TestCase.php): remembers what was created and deleted, and can be
 * told to fail.
 */
class FakeVoiceProvider implements VoiceProvider
{
    /** @var array<string, array<string, mixed>> created assistants, by id */
    public array $assistants = [];

    /** @var list<string> */
    public array $deleted = [];

    private bool $failing = false;

    public function fail(): static
    {
        $this->failing = true;

        return $this;
    }

    public function createAssistant(array $config): string
    {
        if ($this->failing) {
            throw new VoiceException('The fake voice provider is down.');
        }

        $id = 'asst_fake_'.(count($this->assistants) + 1);
        $this->assistants[$id] = $config;

        return $id;
    }

    public function deleteAssistant(string $id): void
    {
        $this->deleted[] = $id;
    }

    public function publicKey(): string
    {
        return 'pk_fake';
    }
}
