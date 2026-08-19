<?php

namespace App\Services\Fraud;

use Adoor\Hashing;
use App\Enums\RiskAction;
use App\Exceptions\FraudBlockedException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fraud/risk client backed by the Masenu consortium API (a fraud-intelligence
 * network: POST an entity, get a real-time risk decision). When disabled every
 * check allows — nothing to configure locally. When enabled it calls Masenu's
 * `/v1/lookups`, sending the entity **edge-hashed** with the consortium pepper
 * (raw MSISDNs never leave the box), and blocks when the recommended action is
 * `block_on` or the score crosses the threshold. On a Masenu error it fails open
 * or closed per `fail_open`. Test/local runs can `force()` a decision.
 */
class MasenuClient
{
    /** Test/local override: when set, assess() returns this verbatim. */
    private ?RiskDecision $forced = null;

    /** Screen an entity and throw if the network says block. */
    public function assertAllowed(string $msisdn, array $context = []): void
    {
        $decision = $this->assess($msisdn, $context);

        if ($decision->isBlocked()) {
            throw new FraudBlockedException($decision);
        }
    }

    /** @param array<string,mixed> $context channel/amount/currency, for scoring */
    public function assess(string $entity, array $context = []): RiskDecision
    {
        if ($this->forced) {
            return $this->forced;
        }

        if (! config('psp.fraud.enabled')) {
            return RiskDecision::allow('fraud checks disabled');
        }

        return $this->viaMasenu($entity, $context);
    }

    /** Force a decision (tests / local demos without a running Masenu). */
    public function force(?RiskDecision $decision): self
    {
        $this->forced = $decision;

        return $this;
    }

    private function viaMasenu(string $entity, array $context): RiskDecision
    {
        try {
            $response = Http::baseUrl(rtrim((string) config('psp.fraud.base_url'), '/'))
                ->withToken((string) config('psp.fraud.api_key'))
                ->timeout((int) config('psp.fraud.timeout', 4))
                ->acceptJson()
                ->post('/v1/lookups', [
                    'identifiers' => [[
                        'kind' => 'msisdn',
                        'value_hash' => $this->edgeHash($entity),
                        'pepper_v' => (int) config('psp.fraud.pepper_v', 1),
                    ]],
                ]);

            if (! $response->successful()) {
                return $this->onError("masenu HTTP {$response->status()}");
            }

            return $this->fromMasenu($response->json() ?? []);
        } catch (Throwable $e) {
            return $this->onError('masenu unreachable: '.$e->getMessage());
        }
    }

    /**
     * Edge hash (h1): HMAC-SHA256(consortium_pepper, normalize(entity)). Masenu
     * only ever sees the hash. Delegated to the official SDK (`masenu/adoor`) so
     * normalization has ONE source of truth shared with the platform and the
     * Python/TS SDKs — the digests only join if they match byte-for-byte, so we
     * never hand-roll a second copy that can drift. The entity here is always an
     * MSISDN; the SDK canonicalises a Ghana local number (0XXXXXXXXX) to E.164.
     */
    private function edgeHash(string $entity): string
    {
        return Hashing::hashIdentifier('msisdn', $entity, (string) config('psp.fraud.pepper'));
    }

    /** Map Masenu's response (risk_band / recommended_action / risk_score) to a decision. */
    private function fromMasenu(array $body): RiskDecision
    {
        $score = (int) ($body['risk_score'] ?? 0);
        $band = (string) ($body['risk_band'] ?? 'low');
        $recommended = strtolower((string) ($body['recommended_action'] ?? 'allow'));
        $blockOn = strtolower((string) config('psp.fraud.block_on', 'block'));

        $action = match (true) {
            $recommended === $blockOn => RiskAction::Block,
            $score >= (int) config('psp.fraud.block_threshold', 80) => RiskAction::Block,
            in_array($recommended, ['review', 'monitor', 'challenge'], true) => RiskAction::Review,
            default => RiskAction::Allow,
        };

        return new RiskDecision($action, $score, $band, "masenu: {$recommended}", $body);
    }

    private function onError(string $reason): RiskDecision
    {
        return config('psp.fraud.fail_open', true)
            ? RiskDecision::allow("fail-open ({$reason})")
            : RiskDecision::block("fail-closed ({$reason})");
    }
}
