<?php

namespace App\Support\Images;

/**
 * A picture straight from the provider, before it's checked and stored.
 */
final class GeneratedImage
{
    public function __construct(public readonly string $bytes, public readonly ?string $providerJobId = null) {}
}
