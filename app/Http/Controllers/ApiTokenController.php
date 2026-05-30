<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ApiTokenController extends Controller
{
    /**
     * Regenerate the user's API token.
     */
    public function regenerate(Request $request): RedirectResponse
    {
        $request->user()->regenerateApiToken();

        return back();
    }
}