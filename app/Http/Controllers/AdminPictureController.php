<?php

namespace App\Http\Controllers;

use App\Models\Image;
use App\Models\ImageCacheEntry;
use Symfony\Component\HttpFoundation\Response;

/**
 * A picture from a contact's shared cache, for the admin gallery (/admin/pictures/{contact}). Only cached pictures:
 * ones made for a single user stay private to that user.
 */
class AdminPictureController extends Controller
{
    public function __invoke(Image $image): Response
    {
        abort_unless(ImageCacheEntry::where('image_id', $image->id)->exists(), 404);

        $contents = $image->contents() ?? abort(404);

        return response($contents, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
