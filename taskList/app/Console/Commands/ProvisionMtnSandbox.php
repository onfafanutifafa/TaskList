<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * One-time helper: mints the MTN MoMo *sandbox* API user + API key for each
 * product, given the subscription keys you copied from momodeveloper.mtn.com.
 * Prints the values (or writes them to .env with --write). Sandbox only —
 * production credentials are issued through MTN's onboarding, not this call.
 */
class ProvisionMtnSandbox extends Command
{
    protected $signature = 'momo:provision-sandbox
        {--product=* : collection and/or disbursement (default: both)}
        {--write : Write the minted API user + key straight into .env}';

    protected $description = 'Provision MTN MoMo sandbox API user + key for the configured subscription keys';

    public function handle(): int
    {
        $base = rtrim(config('psp.providers.mtn_momo.base_url'), '/');
        $callbackHost = config('psp.providers.mtn_momo.callback_host');
        $products = $this->option('product') ?: ['collection', 'disbursement'];
        $env = [];

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
            $env["{$prefix}_API_USER"] = $apiUser;
            $env["{$prefix}_API_KEY"] = $key->json('apiKey');
        }

        if ($env === []) {
            return self::FAILURE;
        }

        if ($this->option('write')) {
            $this->writeEnv($env);
            $this->info('Wrote '.count($env).' value(s) to .env. Restart any running server, then test a collection.');

            return self::SUCCESS;
        }

        $this->newLine();
        foreach ($env as $k => $v) {
            $this->line("  <comment>{$k}</comment>={$v}");
        }
        $this->newLine();
        $this->info('Paste the values above into .env (or re-run with --write), then test a collection.');

        return self::SUCCESS;
    }

    /** Upsert KEY=value lines in the project .env, preserving everything else. */
    private function writeEnv(array $pairs): void
    {
        $path = base_path('.env');
        $contents = is_file($path) ? file_get_contents($path) : '';

        foreach ($pairs as $key => $value) {
            $line = $key.'='.$value;
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            $contents = preg_match($pattern, $contents)
                ? preg_replace($pattern, $line, $contents)
                : rtrim($contents, "\n")."\n".$line."\n";
        }

        file_put_contents($path, $contents);
    }
}
