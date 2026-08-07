<?php

namespace Database\Seeders;

use App\Enums\ApiKeyMode;
use App\Models\ApiKey;
use App\Models\Merchant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $merchant = Merchant::firstOrCreate(
            ['email' => 'demo@node.test'],
            [
                'name' => 'Demo Merchant',
                'country' => 'GH',
                'default_currency' => 'GHS',
                'webhook_url' => null,
                'webhook_secret' => 'whsec_'.Str::random(40),
            ],
        );

        if ($merchant->apiKeys()->count() === 0) {
            [, $secret] = ApiKey::issue($merchant, ApiKeyMode::Test, 'seed');

            $this->command?->newLine();
            $this->command?->info("Demo merchant: {$merchant->id}");
            $this->command?->warn("Test API key (shown once): {$secret}");
            $this->command?->newLine();
        }
    }
}
