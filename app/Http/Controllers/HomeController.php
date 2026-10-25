<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where "home" is after logging in (the starter kit's `dashboard` route): admins go to /admin, everyone else to /app.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        return redirect()->route($user->is_admin ? 'admin.home' : 'app.home');
    }
}
