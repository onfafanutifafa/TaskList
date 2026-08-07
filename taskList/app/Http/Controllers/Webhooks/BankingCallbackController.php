<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\ProviderCallback;
use App\Models\VirtualAccount;
use App\Services\Transactions\VirtualAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Inbound "money arrived in a virtual account" events from the banking partner.
 * The partner is our own trusted integration, so this DOES credit the merchant —
 * but only after an HMAC-SHA256 signature check (over "{ts}.{rawBody}") and a
 * replay window. `{account}` is the virtual account id. Idempotent on the
 * partner's payment reference. Currency must match the account.
 */
class BankingCallbackController extends Controller
{
    private const REPLAY_TOLERANCE_SECONDS = 300;

    public function __construct(private readonly VirtualAccountService $accounts) {}

    public function __invoke(Request $request, string $account): JsonResponse
    {
        $secret = config('psp.banking.webhook_secret');

        if (empty($secret)) {
            throw new ServiceUnavailableHttpException(null, 'Banking webhook is not configured.');
        }

        $valid = $this->signatureValid($request, $secret);

        ProviderCallback::create([
            'provider' => 'baas_banking',
            'product' => 'virtual_account',
            'reference' => $account,
            'payload' => $request->all(),
            'signature_valid' => $valid,
            'processed_at' => now(),
        ]);

        if (! $valid) {
            return response()->json(['error' => ['type' => 'signature_invalid', 'message' => 'Bad signature.']], 401);
        }

        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string'],
            'payment_reference' => ['required', 'string', 'max:100'],
            'sender_name' => ['nullable', 'string', 'max:140'],
        ]);

        $virtualAccount = VirtualAccount::where('id', $account)->where('status', 'active')->first();

        if (! $virtualAccount || strtoupper($data['currency']) !== $virtualAccount->currency) {
            return response()->json(['matched' => false]);
        }

        $transaction = $this->accounts->recordIncomingPayment(
            $virtualAccount,
            (int) $data['amount_minor'],
            $data['payment_reference'],
            $data['sender_name'] ?? null,
        );

        return response()->json(['matched' => true, 'status' => $transaction->status->value]);
    }

    private function signatureValid(Request $request, string $secret): bool
    {
        $header = $request->header('X-Banking-Signature', '');

        if (! preg_match('/t=(\d+),v1=([a-f0-9]+)/', $header, $m)) {
            return false;
        }

        [, $timestamp, $signature] = $m;

        if (abs(now()->timestamp - (int) $timestamp) > self::REPLAY_TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$request->getContent()}", $secret);

        return hash_equals($expected, $signature);
    }
}
