<?php

namespace Database\Factories;

use App\Enums\ApiKeyMode;
use App\Models\ApiKey;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Merchant> */
class MerchantFactory extends Factory
{
    protected $model = Merchant::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'email' => fake()->unique()->companyEmail(),
            'country' => 'GH',
            'default_currency' => 'GHS',
            'status' => 'active',
            'webhook_url' => null,
            'webhook_secret' => 'whsec_'.Str::random(40),
        ];
    }

    /** Create the merchant and return [Merchant, plaintextTestKey]. */
    public function withApiKey(ApiKeyMode $mode = ApiKeyMode::Test): array
    {
        $merchant = $this->create();
        [, $secret] = ApiKey::issue($merchant, $mode, 'test');

        return [$merchant, $secret];
    }
}
