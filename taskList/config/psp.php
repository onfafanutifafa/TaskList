<?php

return [

    'name' => env('PSP_NAME', 'Node PSP'),

    /*
    |--------------------------------------------------------------------------
    | Money
    |--------------------------------------------------------------------------
    | Every amount in this platform is an integer in the currency's MINOR unit
    | (GHS pesewas, KES cents, UGX has no minor unit so factor is 1). Never
    | float, never Decimal in the request path. `supported` maps ISO-4217 code
    | to the number of minor units per major unit.
    */
    'currencies' => [
        'supported' => ['GHS', 'KES', 'UGX', 'EUR', 'USD', 'GBP', 'USDT', 'USDC'],
        'minor_units' => [
            'GHS' => 100,
            'KES' => 100,
            'UGX' => 1,         // Ugandan shilling has no subdivision in practice
            'EUR' => 100,       // MTN sandbox settles in EUR
            'USD' => 100,       // virtual USD receiving accounts
            'GBP' => 100,
            'USDT' => 1000000,  // stablecoins carry 6 decimals on TRON/EVM
            'USDC' => 1000000,
        ],
        'default' => env('PSP_DEFAULT_CURRENCY', 'GHS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fees
    |--------------------------------------------------------------------------
    | Flat basis-points fee taken from collections (1.5% = 150 bps). Real
    | pricing is per-corridor/per-network; this is the platform default.
    */
    'fee_bps' => (int) env('PSP_FEE_BPS', 150),

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    | Mobile-money rails. `network` is the merchant-facing selector on the API
    | (e.g. "mtn"); it resolves to a concrete provider driver here.
    */
    'default_provider' => env('PSP_DEFAULT_PROVIDER', 'mtn_momo'),

    'networks' => [
        'mtn' => 'mtn_momo',
        // 'vodafone' => 'mtn_momo',   // Telecel/Voda also reachable via MoMo aggregation
        // 'mpesa'    => 'mpesa',       // future
        // 'airtel'   => 'airtel',      // future
    ],

    'providers' => [

        'mtn_momo' => [
            'driver' => \App\Providers\MobileMoney\Mtn\MtnMomoProvider::class,
            // Sandbox: https://sandbox.momodeveloper.mtn.com
            // Production: https://proxy.momoapi.mtn.com
            'base_url' => env('MTN_MOMO_BASE_URL', 'https://sandbox.momodeveloper.mtn.com'),
            'environment' => env('MTN_MOMO_ENVIRONMENT', 'sandbox'), // X-Target-Environment
            'currency' => env('MTN_MOMO_CURRENCY', 'EUR'),           // sandbox forces EUR
            'callback_host' => env('MTN_MOMO_CALLBACK_HOST', 'example.com'),
            'callback_url' => env('MTN_MOMO_CALLBACK_URL'),          // where MTN POSTs status
            'collection' => [
                'subscription_key' => env('MTN_MOMO_COLLECTION_SUBSCRIPTION_KEY'),
                'api_user' => env('MTN_MOMO_COLLECTION_API_USER'),
                'api_key' => env('MTN_MOMO_COLLECTION_API_KEY'),
            ],
            'disbursement' => [
                'subscription_key' => env('MTN_MOMO_DISBURSEMENT_SUBSCRIPTION_KEY'),
                'api_user' => env('MTN_MOMO_DISBURSEMENT_API_USER'),
                'api_key' => env('MTN_MOMO_DISBURSEMENT_API_KEY'),
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | FX (currency conversion between wallet balances)
    |--------------------------------------------------------------------------
    | Merchants convert one balance into another (e.g. USDT -> GHS) at a quoted
    | rate. `spread_bps` is the platform markup taken from the converted amount as
    | revenue. Rates are "FROM:TO" = units of TO per 1 FROM; the inverse pair is
    | derived automatically. A production build swaps the config provider for a
    | live rates feed.
    */
    'fx' => [
        'provider' => env('PSP_FX_PROVIDER', 'config'),
        'spread_bps' => (int) env('PSP_FX_SPREAD_BPS', 100), // 1% markup
        'quote_ttl_seconds' => (int) env('PSP_FX_QUOTE_TTL', 60),
        'rates' => [
            'USDT:GHS' => env('FX_USDT_GHS', '15.20'),
            'USDC:GHS' => env('FX_USDC_GHS', '15.20'),
            'USDT:KES' => env('FX_USDT_KES', '129.00'),
            'USD:GHS' => env('FX_USD_GHS', '15.10'),
            'GBP:GHS' => env('FX_GBP_GHS', '19.30'),
            'EUR:GHS' => env('FX_EUR_GHS', '16.50'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Crypto deposits (stablecoin on-ramp)
    |--------------------------------------------------------------------------
    | Merchants receive stablecoins to a per-deposit address; an off-box chain
    | watcher (a node/indexer) confirms the on-chain payment and calls our signed
    | webhook. Confirmed deposits credit the merchant's balance in the asset (no
    | FX to fiat in v1). `assets` maps an asset to the chains we accept it on and
    | how many confirmations are required before we settle.
    */
    'crypto' => [
        'default_provider' => env('PSP_CRYPTO_PROVIDER', 'watcher'),
        'deposit_ttl_minutes' => (int) env('PSP_CRYPTO_DEPOSIT_TTL', 60),

        // Shared secret the chain watcher signs its callbacks with (HMAC-SHA256).
        'watcher_secret' => env('PSP_CRYPTO_WATCHER_SECRET'),

        'assets' => [
            'USDT' => [
                'tron' => [
                    'confirmations' => (int) env('CRYPTO_USDT_TRON_CONFIRMATIONS', 20),
                    // Receiving address the watcher funds/monitors. A production
                    // build derives a unique address per deposit from an xpub; here
                    // one address + a per-deposit memo/tag disambiguates payments.
                    'address' => env('CRYPTO_USDT_TRON_ADDRESS'),
                ],
                'ethereum' => [
                    'confirmations' => (int) env('CRYPTO_USDT_ETH_CONFIRMATIONS', 12),
                    'address' => env('CRYPTO_USDT_ETH_ADDRESS'),
                ],
            ],
            'USDC' => [
                'base' => [
                    'confirmations' => (int) env('CRYPTO_USDC_BASE_CONFIRMATIONS', 12),
                    'address' => env('CRYPTO_USDC_BASE_ADDRESS'),
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Virtual receiving accounts (foreign-currency on-ramp)
    |--------------------------------------------------------------------------
    | A merchant gets a virtual USD/GBP/EUR bank account (issued by a banking-as-a-
    | service partner). Incoming international payments are reported by the BaaS via
    | a signed webhook and credit the merchant's balance in that currency. The
    | `rails` block is display/metadata per currency.
    */
    'banking' => [
        'provider' => env('PSP_BANKING_PROVIDER', 'baas'),
        'webhook_secret' => env('PSP_BANKING_WEBHOOK_SECRET'),
        'currencies' => ['USD', 'GBP', 'EUR'],
        'rails' => [
            'USD' => ['rail' => 'ach', 'bank_name' => env('BANK_USD_NAME', 'Node Bank USA')],
            'GBP' => ['rail' => 'faster_payments', 'bank_name' => env('BANK_GBP_NAME', 'Node Bank UK')],
            'EUR' => ['rail' => 'sepa', 'bank_name' => env('BANK_EUR_NAME', 'Node Bank EU')],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks (outbound, to merchants)
    |--------------------------------------------------------------------------
    */
    'webhooks' => [
        'timeout' => (int) env('PSP_WEBHOOK_TIMEOUT', 10),
        'max_attempts' => (int) env('PSP_WEBHOOK_MAX_ATTEMPTS', 6),
    ],

];
