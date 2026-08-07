<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\ProviderCallback;
use App\Models\Transaction;
use App\Services\Transactions\CryptoDepositService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Inbound on-chain payment notifications from the chain watcher. Unlike a payer,
 * the watcher is our own trusted component, so its report DOES move money — but
 * only after an HMAC-SHA256 signature check (over "{ts}.{rawBody}") and a replay
 * window. `{reference}` is the deposit transaction id.
 */
class CryptoWatcherCallbackController extends Controller
{
    private const REPLAY_TOLERANCE_SECONDS = 300;

    public function __construct(private readonly CryptoDepositService $deposits) {}

    public function __invoke(Request $request, string $reference): JsonResponse
    {
        $secret = config('psp.crypto.watcher_secret');

        if (empty($secret)) {
            throw new ServiceUnavailableHttpException(null, 'Crypto watcher is not configured.');
        }

        $valid = $this->signatureValid($request, $secret);

        ProviderCallback::create([
            'provider' => 'crypto_watcher',
            'product' => 'crypto_deposit',
            'reference' => $reference,
            'payload' => $request->all(),
            'signature_valid' => $valid,
            'processed_at' => now(),
        ]);

        if (! $valid) {
            return response()->json(['error' => ['type' => 'signature_invalid', 'message' => 'Bad signature.']], 401);
        }

        $transaction = Transaction::with('cryptoDeposit')->where('id', $reference)->first();

        if (! $transaction || ! $transaction->cryptoDeposit) {
            return response()->json(['matched' => false]);
        }

        $updated = $this->deposits->applyWatcherUpdate($transaction->cryptoDeposit, [
            'amount_received_minor' => $request->integer('amount_received_minor'),
            'confirmations' => $request->integer('confirmations'),
            'tx_hash' => $request->string('tx_hash')->toString() ?: null,
        ]);

        return response()->json(['matched' => true, 'status' => $updated->status->value]);
    }

    private function signatureValid(Request $request, string $secret): bool
    {
        $header = $request->header('X-Watcher-Signature', '');

        if (! preg_match('/t=(\d+),v1=([a-f0-9]+)/', $header, $m)) {
            return false;
        }

        [$_, $timestamp, $signature] = $m;

        if (abs(now()->timestamp - (int) $timestamp) > self::REPLAY_TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$request->getContent()}", $secret);

        return hash_equals($expected, $signature);
    }
}
