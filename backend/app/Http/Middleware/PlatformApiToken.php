<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;

/** Amendment 2: `Authorization: Bearer <token>` for the read-only /api/v1 endpoints. */
class PlatformApiToken
{
    public function handle(Request $request, Closure $next)
    {
        $token = ApiToken::findActive($request->bearerToken());
        if (! $token) {
            return response()->json(['message' => 'A valid API token is needed.', 'code' => 'api_token'], 401);
        }
        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
