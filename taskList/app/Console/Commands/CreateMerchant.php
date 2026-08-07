<?php

namespace App\Console\Commands;

use App\Enums\ApiKeyMode;
use App\Models\ApiKey;
use App\Models\Merchant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateMerchant extends Command
{
    protected $signature = 'psp:create-merchant
        {name : Business name}
        {email : Contact email}
        {--country=GH}
        {--currency=GHS}
        {--webhook= : Optional webhook URL}
        {--live : Also issue a live key (default issues a test key)}';

    protected $description = 'Onboard a merchant and issue an API key (secret shown once)';

    public function handle(): int
    {
        $merchant = Merchant::create([
            'name' => $this->argument('name'),
            'email' => $this->argument('email'),
            'country' => strtoupper($this->option('country')),
            'default_currency' => strtoupper($this->option('currency')),
            'webhook_url' => $this->option('webhook') ?: null,
            'webhook_secret' => 'whsec_'.Str::random(40),
        ]);

        $mode = $this->option('live') ? ApiKeyMode::Live : ApiKeyMode::Test;
        [, $secret] = ApiKey::issue($merchant, $mode, 'default');

        $this->info("Merchant created: {$merchant->name} ({$merchant->id})");
        $this->newLine();
        $this->line('  API key (shown once — store it now):');
        $this->line("  <comment>{$secret}</comment>");
        $this->newLine();
        $this->line("  Webhook secret: <comment>{$merchant->webhook_secret}</comment>");

        return self::SUCCESS;
    }
}
