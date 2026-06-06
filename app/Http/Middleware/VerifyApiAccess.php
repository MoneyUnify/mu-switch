<?php

namespace App\Http\Middleware;

use App\ApiResponse;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyApiAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): JsonResponse|Response
    {
        // Ensure the request has a valid API token
        if (! $request->bearerToken()) {
            return ApiResponse::error('Unauthorized', 401);
        }
        // verify against db and insert user in the request
        $user = User::where('api_token', $request->bearerToken())->first();
        if (! $user) {
            return ApiResponse::error('Invalid API token', 401);
        }
        $request->merge(['user' => $user]);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
