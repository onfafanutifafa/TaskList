<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a merchant from `Authorization: Bearer sk_(test|live)_...`.
 * The presented secret is never stored; we look it up by its SHA-256 hash and
 * stash the merchant + key on the request for downstream handlers.
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = $request->bearerToken();

        if (! $secret) {
            throw new AuthenticationException;
        }

        $apiKey = ApiKey::with('merchant')->where('key_hash', ApiKey::hash($secret))->first();

        if (! $apiKey || ! $apiKey->merchant || ! $apiKey->merchant->isActive()) {
            throw new AuthenticationException;
        }

        // Cheap, best-effort last-used stamp; skip if it was updated in the last minute.
        if (! $apiKey->last_used_at || $apiKey->last_used_at->lt(now()->subMinute())) {
            $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('merchant', $apiKey->merchant);
        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }
}
