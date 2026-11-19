<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs in the operator MCP server's caller by the account's API token, the
 * same token the REST API uses.
 */
class AuthenticateMcpOperator
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->bearerToken() ? User::where('api_token', $request->bearerToken())->first() : null;

        if (! $user) {
            return response()->json(['error' => 'Send your MoneyUnify API token as a Bearer token.'], 401);
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
