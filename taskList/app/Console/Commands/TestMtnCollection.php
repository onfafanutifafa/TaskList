<?php

namespace App\Console\Commands;

use App\Providers\MobileMoney\Contracts\MoneyRequest;
use App\Providers\MobileMoney\Contracts\ProviderStatus;
use App\Providers\MobileMoney\ProviderManager;
use App\Support\Money;
use Illuminate\Support\Str;

/**
 * Fires a REAL request-to-pay against the configured MTN environment (sandbox by
 * default) and polls its status until terminal — a self-contained proof that the
 * subscription key + provisioned API user/key work end to end. Talks to the
 * provider directly, so it touches no ledger and moves no real money in sandbox.
 */
class TestMtnCollection extends \Illuminate\Console\Command
{
    protected $signature = 'momo:test-collection
        {--amount=1000 : amount in minor units (sandbox currency is EUR; 1000 = 10.00)}
        {--phone=46733123453 : payer MSISDN}
        {--tries=8 : status polls before giving up}';

    protected $description = 'Fire a real MTN sandbox request-to-pay and poll it to a terminal status';

    public function handle(ProviderManager $providers): int
    {
        $provider = $providers->driver('mtn_momo');
        $currency = config('psp.providers.mtn_momo.currency', 'EUR');
        $reference = (string) Str::uuid();
        $amount = new Money((int) $this->option('amount'), $currency);

        $this->line("→ requesttopay  ref=<info>{$reference}</info>  {$amount->toMajorString()} {$currency}  payer={$this->option('phone')}");

        $result = $provider->collect(new MoneyRequest(
            reference: $reference,
            msisdn: (string) $this->option('phone'),
            amount: $amount,
            externalId: $reference,
            narration: 'Node sandbox test',
        ));

        if (! $result->accepted) {
            $this->error("  initiation failed: [{$result->failureCode}] {$result->failureReason}");

            return self::FAILURE;
        }

        $this->info('  accepted (HTTP 202) — polling status…');

        for ($i = 1; $i <= (int) $this->option('tries'); $i++) {
            sleep(2);
            $status = $provider->collectionStatus($reference);

            $this->line("  poll {$i}: <comment>{$status->status->value}</comment>".
                ($status->providerReference ? "  finTxnId={$status->providerReference}" : ''));

            if ($status->status !== ProviderStatus::Pending) {
                $ok = $status->status === ProviderStatus::Successful;
                $this->{$ok ? 'info' : 'error'}('  terminal: '.strtoupper($status->status->value));

                return $ok ? self::SUCCESS : self::FAILURE;
            }
        }

        $this->warn('  still pending after all polls — try again shortly.');

        return self::SUCCESS;
    }
}
