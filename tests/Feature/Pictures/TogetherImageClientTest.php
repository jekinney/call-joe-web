<?php

use App\Contracts\ImageGenerator;
use App\Exceptions\ImageException;
use App\Providers\AppServiceProvider;
use App\Support\Images\TogetherImageClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// The Together AI client, against a faked API.

function together(string $key = 'tg_test'): TogetherImageClient
{
    return new TogetherImageClient($key, 'https://api.together.test/v1/', 'black-forest-labs/FLUX.1-schnell', 4, 30);
}

it('asks for one picture and decodes it', function () {
    Http::fake(['api.together.test/v1/images/generations' => Http::response(['id' => 'img-123', 'object' => 'list', 'data' => [['index' => 0, 'b64_json' => base64_encode('JPEGBYTES'), 'type' => 'b64_json']]])]);

    $image = together()->generate('a salad', 768, 1024);

    expect($image->bytes)->toBe('JPEGBYTES')->and($image->providerJobId)->toBe('img-123')->and(together()->name())->toBe('together');
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer tg_test') && $request->data() === [
        'model' => 'black-forest-labs/FLUX.1-schnell', 'prompt' => 'a salad', 'width' => 768, 'height' => 1024,
        'steps' => 4, 'n' => 1, 'response_format' => 'base64', 'output_format' => 'jpeg',
    ]);
});

it('explains what went wrong', function (Closure $fake, string $message) {
    $fake();

    expect(fn () => together()->generate('a salad', 768, 1024))->toThrow(ImageException::class, $message);
})->with([
    'an error' => [fn () => Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid model']], 400)]), 'Together answered 400: Invalid model'],
    'an error without details' => [fn () => Http::fake(['*' => Http::response('Bad gateway', 502)]), 'Together answered 502: Bad gateway'],
    'no picture' => [fn () => Http::fake(['*' => Http::response(['data' => []])]), 'Together sent no picture.'],
    'unreachable' => [fn () => Http::fake(fn () => throw new ConnectionException('timed out')), "Couldn't reach Together: timed out"],
]);

it('needs a key', function () {
    together('')->generate('a salad', 768, 1024);
})->throws(ImageException::class, 'No Together API key is set (TOGETHER_API_KEY).');

it('is the picture provider the app uses', function () {
    $this->app->forgetInstance(ImageGenerator::class);
    $this->app->offsetUnset(ImageGenerator::class);
    (new AppServiceProvider($this->app))->register();

    expect(app(ImageGenerator::class))->toBeInstanceOf(TogetherImageClient::class);
});
