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
