<?php

namespace App\Support\Images;

use App\Contracts\ImageGenerator;
use App\Exceptions\ImageException;

/**
 * Stands in for the picture provider in tests (bound in tests/TestCase.php): makes a small real JPEG (with a bit of
 * metadata, to prove it gets stripped), or a black one (what a provider's safety checker sends back), or fails.
 */
class FakeImageGenerator implements ImageGenerator
{
    /** What each fake picture "costs": two cents, like Grok Imagine. */
    public const COST_MICROS = 20_000;

    /** @var list<string> every prompt asked for */
    public array $prompts = [];

    private ?string $failure = null;

    private bool $black = false;

    public function name(): string
    {
        return 'fake';
    }

    public function fail(string $message = 'The fake provider is down.'): static
    {
        $this->failure = $message;

        return $this;
    }

    /** The next pictures come back black, as if the provider's safety checker refused them. */
    public function refuse(): static
    {
        $this->black = true;

        return $this;
    }

    public function generate(string $prompt, int $width, int $height): GeneratedImage
    {
        $this->prompts[] = $prompt;
        if ($this->failure !== null) {
            throw new ImageException($this->failure);
        }

        $image = imagecreatetruecolor(max(1, $width), max(1, $height));
        imagefill($image, 0, 0, $this->black ? 0 : (int) imagecolorallocate($image, 200, 140, 60));
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();

        // A comment segment right after the start marker, like camera metadata.
        $withMetadata = substr($jpeg, 0, 2)."\xFF\xFE".pack('n', 16).'Secret-GPS-00'.substr($jpeg, 2);

        return new GeneratedImage($withMetadata, 'fake-'.count($this->prompts), self::COST_MICROS);
    }
}
