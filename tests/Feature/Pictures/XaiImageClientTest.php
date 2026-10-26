<?php

use App\Contracts\ImageGenerator;
use App\Exceptions\ImageException;
use App\Providers\AppServiceProvider;
use App\Support\Images\XaiImageClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// The Grok Imagine client, against a faked API.

function grokImages(string $key = 'xai_test'): XaiImageClient
{
    return new XaiImageClient($key, 'https://xai.test/v1/', 'grok-imagine-image', 5);
}

it('asks for one picture in the shape of a phone photo, and decodes it', function () {
    Http::fake(['xai.test/v1/images/generations' => Http::response(['data' => [['b64_json' => base64_encode('JPEGBYTES'), 'mime_type' => 'image/jpeg']], 'usage' => ['cost_in_usd_ticks' => 200_000_000]])]);

    $image = grokImages()->generate('a salad', 768, 1024);

    expect($image->bytes)->toBe('JPEGBYTES')->and($image->costMicros)->toBe(20_000) // two cents, in millionths of a dollar
        ->and($image->providerJobId)->toBeNull()->and(grokImages()->name())->toBe('xai');
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer xai_test') && $request->data() === [
        'model' => 'grok-imagine-image', 'prompt' => 'a salad', 'n' => 1, 'response_format' => 'b64_json', 'aspect_ratio' => '3:4',
    ]);
});

it('works out the aspect ratio from the size', function (int $width, int $height, string $ratio) {
    Http::fake(['*' => Http::response(['data' => [['b64_json' => base64_encode('x')]]])]);

    grokImages()->generate('a salad', $width, $height);

    Http::assertSent(fn (Request $request) => $request['aspect_ratio'] === $ratio);
})->with([[768, 1024, '3:4'], [1024, 1024, '1:1'], [1920, 1080, '16:9']]);

it('keeps going when xAI reports no cost', function () {
    Http::fake(['*' => Http::response(['data' => [['b64_json' => base64_encode('x')]]])]);

    expect(grokImages()->generate('a salad', 768, 1024)->costMicros)->toBe(0);
});

it('explains what went wrong', function (Closure $fake, string $message) {
    $fake();

    expect(fn () => grokImages()->generate('a salad', 768, 1024))->toThrow(ImageException::class, $message);
})->with([
    'a refusal' => [fn () => Http::fake(['*' => Http::response(['error' => ['message' => 'Content violates the usage guidelines']], 400)]), 'Grok answered 400: Content violates the usage guidelines'],
    'a plain-text error' => [fn () => Http::fake(['*' => Http::response(['error' => 'Invalid key'], 401)]), 'Grok answered 401: Invalid key'],
    'no details' => [fn () => Http::fake(['*' => Http::response('Bad gateway', 502)]), 'Grok answered 502: Bad gateway'],
    'a structured error' => [fn () => Http::fake(['*' => Http::response(['error' => ['code' => 7]], 500)]), 'Grok answered 500: {"code":7}'],
    'no picture' => [fn () => Http::fake(['*' => Http::response(['data' => []])]), 'Grok sent no picture.'],
    'unreachable' => [fn () => Http::fake(fn () => throw new ConnectionException('timed out')), "Couldn't reach Grok: timed out"],
]);

it('needs a key', function () {
    grokImages('')->generate('a salad', 768, 1024);
})->throws(ImageException::class, 'No Grok API key is set (XAI_API_KEY).');

it('is the picture provider the app uses, on the same xAI key as texting', function () {
    config(['services.xai.key' => 'xai-live', 'services.xai.base_url' => 'https://xai.test/v1']);
    $this->app->forgetInstance(ImageGenerator::class);
    $this->app->offsetUnset(ImageGenerator::class);
    (new AppServiceProvider($this->app))->register();

    expect(app(ImageGenerator::class))->toBeInstanceOf(XaiImageClient::class);
});
