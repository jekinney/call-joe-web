<?php

namespace App\Support\Images;

use App\Contracts\ImageGenerator;
use App\Exceptions\ImageException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Pictures from Together AI (FLUX.1 schnell by default: a few seconds, answered in the same request). Its safety
 * checker always stays on: a picture it refuses comes back black, which App\Services\Pictures treats as blocked.
 */
class TogetherImageClient implements ImageGenerator
{
    public function __construct(
        private string $key,
        private string $baseUrl,
        private string $model,
        private int $steps,
        private int $timeout,
    ) {}

    public function name(): string
    {
        return 'together';
    }

    public function generate(string $prompt, int $width, int $height): GeneratedImage
    {
        if ($this->key === '') {
            throw new ImageException('No Together API key is set (TOGETHER_API_KEY).');
        }

        try {
            $response = Http::withToken($this->key)->acceptJson()->timeout($this->timeout)
                ->post(rtrim($this->baseUrl, '/').'/images/generations', [
                    'model' => $this->model,
                    'prompt' => $prompt,
                    'width' => $width,
                    'height' => $height,
                    'steps' => $this->steps,
                    'n' => 1,
                    'response_format' => 'base64',
                    'output_format' => 'jpeg',
                ]);
        } catch (ConnectionException $e) {
            throw new ImageException("Couldn't reach Together: {$e->getMessage()}");
        }

        if ($response->failed()) {
            throw new ImageException("Together answered {$response->status()}: ".mb_substr((string) ($response->json('error.message') ?? $response->body()), 0, 150));
        }

        $bytes = base64_decode((string) $response->json('data.0.b64_json'), true);
        if (! $bytes) {
            throw new ImageException('Together sent no picture.');
        }

        return new GeneratedImage($bytes, $response->json('id'));
    }
}
