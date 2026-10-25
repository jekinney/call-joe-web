<?php

namespace App\Http\Controllers;

use App\Models\Image;
use App\Models\ImageRequest;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A picture a contact sent, only to the user it was sent to (pictures are stored privately).
 */
class ImageController extends Controller
{
    public function __invoke(Request $request, Image $image): Response
    {
        $sentToThem = ImageRequest::where('image_id', $image->id)
            ->whereHas('userContact', fn ($q) => $q->where('user_id', $request->user()?->id))
            ->exists();
        abort_unless($sentToThem, 404);

        $contents = $image->contents() ?? abort(404);

        return response($contents, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
