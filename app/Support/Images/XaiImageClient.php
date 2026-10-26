<?php

namespace App\Support\Images;

use App\Contracts\ImageGenerator;
use App\Enums\LlmProvider;
use App\Exceptions\ImageException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Pictures from Grok Imagine (xAI): a flat $0.02 each for grok-imagine-image, about 7 seconds, answered in the same
 * request. xAI reviews what it makes under its content policy, and says what it charged, which is kept per picture.
 */
class XaiImageClient implements ImageGenerator
{
    /** xAI reports cost in ten-billionths of a dollar. */
    private const TICKS_PER_MICRO = 10_000;

    public function __construct(private string $key, private string $baseUrl, private string $model, private int $timeoutSeconds = 90) {}

    public function name(): string
    {
        return 'xai';
    }

    public function generate(string $prompt, int $width, int $height): GeneratedImage
    {
        if ($this->key === '') {
            throw new ImageException('No Grok API key is set ('.LlmProvider::Xai->envKey().').');
        }

        try {
            $response = Http::withToken($this->key)->acceptJson()->timeout($this->timeoutSeconds)
                ->post(rtrim($this->baseUrl, '/').'/images/generations', [
                    'model' => $this->model,
                    'prompt' => $prompt,
                    'n' => 1,
                    'response_format' => 'b64_json',
                    'aspect_ratio' => $this->aspectRatio($width, $height),
                ]);
        } catch (ConnectionException $e) {
            throw new ImageException("Couldn't reach Grok: {$e->getMessage()}");
        }

        if ($response->failed()) {
            $why = $response->json('error.message') ?? $response->json('error') ?? $response->body();

            throw new ImageException("Grok answered {$response->status()}: ".mb_substr(is_string($why) ? $why : (string) json_encode($why), 0, 150));
        }

        $bytes = base64_decode((string) $response->json('data.0.b64_json'), true);
        if (! $bytes) {
            throw new ImageException('Grok sent no picture.');
        }

        return new GeneratedImage($bytes, null, (int) round((int) $response->json('usage.cost_in_usd_ticks', 0) / self::TICKS_PER_MICRO));
    }

    /** 768 by 1024 is "3:4". */
    private function aspectRatio(int $width, int $height): string
    {
        $divisor = max(1, $this->gcd($width, $height));

        return intdiv($width, $divisor).':'.intdiv($height, $divisor);
    }

    private function gcd(int $a, int $b): int
    {
        return $b === 0 ? $a : $this->gcd($b, $a % $b);
    }
}
