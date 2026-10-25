<?php

namespace App\Jobs;

use App\Models\ImageRequest;
use App\Services\Pictures;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Makes and sends one picture a contact decided to send (App\Services\Pictures::generate). Failures are handled
 * inside (the picture is dropped and its count given back), so the job never retries and never sends twice.
 */
class GenerateImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $requestId) {}

    public function handle(Pictures $pictures): void
    {
        if ($request = ImageRequest::find($this->requestId)) {
            $pictures->generate($request);
        }
    }
}
