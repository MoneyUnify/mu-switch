<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ApiTokenController extends Controller
{
    /**
     * Display the user's API token.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'token' => $request->user()->ensureApiToken(),
        ]);
    }

    /**
     * Regenerate the user's API token.
     */
    public function regenerate(Request $request): RedirectResponse|JsonResponse
    {
        $token = $request->user()->regenerateApiToken();

        if ($request->wantsJson()) {
            return response()->json([
                'token' => $token,
            ]);
        }

        return back();
    }
}
