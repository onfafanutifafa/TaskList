<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * One-time helper: mints the MTN MoMo *sandbox* API user + API key for each
 * product, given the subscription keys you copied from momodeveloper.mtn.com.
 * Prints the values to paste into .env. Sandbox only — production credentials
 * are issued through MTN's onboarding, not this call.
 */
class ProvisionMtnSandbox extends Command
{
    protected $signature = 'momo:provision-sandbox {--product=* : collection and/or disbursement (default: both)}';

    protected $description = 'Provision MTN MoMo sandbox API user + key for the configured subscription keys';

    public function handle(): int
    {
        $base = rtrim(config('psp.providers.mtn_momo.base_url'), '/');
        $callbackHost = config('psp.providers.mtn_momo.callback_host');
        $products = $this->option('product') ?: ['collection', 'disbursement'];

        foreach ($products as $product) {
            $subKey = config("psp.providers.mtn_momo.{$product}.subscription_key");

            if (empty($subKey)) {
                $this->error("Missing subscription key for [{$product}]. Set MTN_MOMO_".strtoupper($product)."_SUBSCRIPTION_KEY first.");

                continue;
            }

            $this->line("→ Provisioning <info>{$product}</info>…");
            $apiUser = (string) Str::uuid();

            $create = Http::baseUrl($base)
                ->withHeaders(['Ocp-Apim-Subscription-Key' => $subKey, 'X-Reference-Id' => $apiUser])
                ->post('/v1_0/apiuser', ['providerCallbackHost' => $callbackHost]);

            if (! $create->successful()) {
                $this->error("  apiuser creation failed: HTTP {$create->status()} {$create->body()}");

                continue;
            }

            $key = Http::baseUrl($base)
                ->withHeaders(['Ocp-Apim-Subscription-Key' => $subKey])
                ->post("/v1_0/apiuser/{$apiUser}/apikey");

            if (! $key->successful() || empty($key->json('apiKey'))) {
                $this->error("  apikey creation failed: HTTP {$key->status()} {$key->body()}");

                continue;
            }

            $prefix = 'MTN_MOMO_'.strtoupper($product);
            $this->newLine();
            $this->line("  <comment>{$prefix}_API_USER</comment>={$apiUser}");
            $this->line("  <comment>{$prefix}_API_KEY</comment>={$key->json('apiKey')}");
            $this->newLine();
        }

        $this->info('Done. Paste the values above into your .env, then run a test collection.');

        return self::SUCCESS;
    }
}
