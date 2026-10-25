<?php

namespace App\Contracts;

use App\Exceptions\ImageException;
use App\Support\Images\GeneratedImage;

/**
 * Makes a picture from a prompt (App\Support\Images\TogetherImageClient; a fake in tests).
 */
interface ImageGenerator
{
    /**
     * @throws ImageException when the provider can't make it.
     */
    public function generate(string $prompt, int $width, int $height): GeneratedImage;

    /** A short name for the provider, saved with each request. */
    public function name(): string;
}
