<?php

use App\Exceptions\ProviderException;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Middleware\RequireAbility;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'api.key' => AuthenticateApiKey::class,
            'idempotency' => EnforceIdempotency::class,
            'ability' => RequireAbility::class,
        ]);

        // Hardening headers on every response.
        $middleware->append(SecurityHeaders::class);

        // Behind a load balancer/CDN in prod; trust its forwarding headers so
        // isSecure()/rate-limit-by-IP see the real client + scheme.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every error on the API surface is a JSON envelope, never HTML.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('v1/*') || $request->expectsJson()
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! ($request->is('v1/*') || $request->expectsJson())) {
                return null;
            }

            return response()->json([
                'error' => [
                    'type' => 'validation_error',
                    'message' => 'The request failed validation.',
                    'fields' => $e->errors(),
                ],
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! ($request->is('v1/*') || $request->expectsJson())) {
                return null;
            }

            return response()->json([
                'error' => [
                    'type' => 'authentication_error',
                    'message' => 'Missing or invalid API key.',
                ],
            ], 401);
        });

        $exceptions->render(function (ProviderException $e, Request $request) {
            if (! ($request->is('v1/*') || $request->expectsJson())) {
                return null;
            }

            return response()->json([
                'error' => [
                    'type' => 'provider_error',
                    'message' => 'The payment provider is currently unavailable. Please retry.',
                ],
            ], 502);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! ($request->is('v1/*') || $request->expectsJson())) {
                return null;
            }

            return response()->json([
                'error' => [
                    'type' => 'api_error',
                    'message' => $e->getMessage() ?: 'Request could not be processed.',
                ],
            ], $e->getStatusCode());
        });
    })->create();
