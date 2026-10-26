<?php

namespace App\Http\Controllers;

use App\Services\DataRights;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Downloads everything we keep about the signed-in user as one JSON file. */
class DataExportController extends Controller
{
    public function __invoke(Request $request, DataRights $rights): StreamedResponse
    {
        $data = $rights->export($request->user() ?? abort(401));

        return response()->streamDownload(
            fn () => print json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'call-joe-my-data-'.now()->format('Y-m-d').'.json',
            ['Content-Type' => 'application/json'],
        );
    }
}
