<?php

namespace App\Http\Middleware;

use App\Mcp\MerchantAccess;
use App\Models\Merchant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a merchant into the merchant MCP server with its own access key. The
 * merchant only ever reaches its own payments, links and settlements; the
 * operator's account stays out of reach.
 */
class AuthenticateMcpMerchant
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $merchant = Merchant::findByMcpKey($request->bearerToken());

        if (! $merchant) {
            return response()->json(['error' => 'Send your merchant MCP key as a Bearer token.'], 401);
        }

        app()->instance(MerchantAccess::class, new MerchantAccess($merchant));
        // Request logs land in the operator's logs, attributed to the merchant's account.
        $request->setUserResolver(fn () => $merchant->user);

        return $next($request);
    }
}
