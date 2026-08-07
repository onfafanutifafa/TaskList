<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Enforces API-key scopes: a route tagged `ability:payouts:write` only runs if
 * the presented key carries that scope (or full access). Least privilege — a
 * read-only or collections-only key cannot move money out.
 */
class RequireAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        /** @var ApiKey|null $key */
        $key = $request->attributes->get('api_key');

        if (! $key || ! $key->hasAbility($ability)) {
            throw new AccessDeniedHttpException("This API key is missing the required scope [{$ability}].");
        }

        return $next($request);
    }
}
