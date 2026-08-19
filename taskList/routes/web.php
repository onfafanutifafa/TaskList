<?php

use Illuminate\Support\Facades\Route;

/*
| The PSP is an API-first platform. The human-facing surface is intentionally
| minimal — the merchant-facing product is the JSON API under routes/api.php.
*/

Route::get('/', fn () => response()->json([
    'service' => config('psp.name'),
    'status' => 'ok',
    'docs' => 'See INSTRUCTIONS.md and routes/api.php',
]));

/*
| Local-only demo surface. The /demo/hash page performs the edge hash IN THE
| BROWSER so a prospect can inspect the pepper + normalize() live. It is gated to
| the `local` environment on purpose: in production the pepper is a member secret
| and the hashing runs server-side (MasenuClient) or via an SDK — never in a page.
*/
if (app()->environment('local')) {
    Route::view('/demo', 'demo.index');
    Route::view('/demo/hash', 'demo.hash');

    // Live network check: runs the REAL fraud screen (App\Services\Fraud\MasenuClient),
    // the same code every payout uses. It edge-hashes the number and calls Masenu's
    // /v1/lookups, then returns the decision + timing for the page to render.
    Route::post('/demo/check', function (\Illuminate\Http\Request $request, \App\Services\Fraud\MasenuClient $masenu) {
        $phone = trim((string) $request->input('phone', ''));
        $t0 = microtime(true);
        $decision = $masenu->assess($phone);
        $backendMs = (int) round((microtime(true) - $t0) * 1000);
        $raw = $decision->raw ?? [];

        return response()->json([
            'action' => $decision->action->value,
            'band' => $decision->band,
            'score' => $decision->score,
            'masenu_latency_ms' => $raw['latency_ms'] ?? null,
            'backend_ms' => $backendMs,
            'hits' => $raw['hits'] ?? [],
            'explanations' => $raw['explanations'] ?? [],
        ]);
    });
}
