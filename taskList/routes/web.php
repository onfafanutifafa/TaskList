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
// 'allpay' / 'sikapay' are demo environments used to run two branded PSP
// instances side by side (same code, different PSP_NAME + Masenu member key,
// shared consortium pepper) for the cross-institution demo.
if (app()->environment(['local', 'allpay', 'sikapay'])) {
    Route::redirect('/demo', '/demo/hash');
    Route::view('/demo/hash', 'demo.hash');       // Live demo (lookup + block/allow)
    Route::view('/demo/submit', 'demo.submit');   // Fraud submission (edge-hash a spreadsheet)

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

    // Fraud submission: the browser edge-hashes an uploaded spreadsheet and posts
    // the ALREADY-HASHED reports here. We forward each to Masenu /v1/reports, which
    // applies the second (central) hash layer server-side. Raw identifiers never
    // reach this endpoint — only h1 digests.
    Route::post('/demo/submit', function (\Illuminate\Http\Request $request) {
        $reports = (array) $request->input('reports', []);
        $base = rtrim((string) config('psp.fraud.base_url'), '/');
        $key = (string) config('psp.fraud.api_key');
        $pepperV = (int) config('psp.fraud.pepper_v', 1);
        $submitted = 0;
        $results = [];

        // Masenu's FraudType enum — anything else from a spreadsheet column falls back to `other`.
        $fraudTypes = [
            'account_takeover', 'mule_account', 'sim_swap', 'social_engineering', 'phishing',
            'fake_investment', 'romance_scam', 'identity_theft', 'agent_fraud', 'chargeback', 'other',
        ];
        $roles = ['perpetrator', 'mule', 'victim', 'instrument'];

        foreach ($reports as $r) {
            $ids = array_values(array_filter(array_map(function ($id) use ($pepperV, $roles) {
                if (empty($id['value_hash'])) {
                    return null;
                }

                return [
                    'kind' => $id['kind'] ?? 'msisdn',
                    'value_hash' => $id['value_hash'],
                    'pepper_v' => $pepperV,
                    'role' => \in_array($id['role'] ?? '', $roles, true) ? $id['role'] : 'perpetrator',
                    'country' => 'GH',
                ];
            }, (array) ($r['identifiers'] ?? []))));

            if ($ids === []) {
                $results[] = ['ok' => false, 'error' => 'no valid identifiers'];

                continue;
            }

            $body = [
                'fraud_type' => \in_array($r['fraud_type'] ?? '', $fraudTypes, true) ? $r['fraud_type'] : 'other',
                'confidence' => 'confirmed',
                'occurred_at' => now()->subMinute()->toIso8601String(),
                'identifiers' => $ids,
            ];
            if (! empty($r['narrative'])) {
                $body['narrative'] = $r['narrative'];
            }

            $resp = \Illuminate\Support\Facades\Http::timeout(8)
                ->withToken($key)
                ->withHeaders(['Idempotency-Key' => 'allpay-' . bin2hex(random_bytes(8))])
                ->acceptJson()
                ->post($base . '/v1/reports', $body);

            $results[] = ['ok' => $resp->successful(), 'status' => $resp->status()];
            if ($resp->successful()) {
                $submitted++;
            }
        }

        return response()->json(['submitted' => $submitted, 'total' => \count($reports), 'results' => $results]);
    });
}
