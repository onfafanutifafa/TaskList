<?php

namespace App\Services\Fraud;

use App\Enums\RiskAction;

/** The fraud engine's verdict. */
final class RiskDecision
{
    /**
     * @param  array<string,mixed>  $raw  the provider's raw payload, for audit
     */
    public function __construct(
        public readonly RiskAction $action,
        public readonly int $score,        // 0–100
        public readonly string $band,      // low | medium | high | critical
        public readonly string $reason,
        public readonly array $raw = [],
    ) {}

    public static function allow(string $reason = 'ok', int $score = 0): self
    {
        return new self(RiskAction::Allow, $score, 'low', $reason);
    }

    public static function block(string $reason, int $score = 100, string $band = 'critical', array $raw = []): self
    {
        return new self(RiskAction::Block, $score, $band, $reason, $raw);
    }

    public function isBlocked(): bool
    {
        return $this->action->isBlocked();
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'action' => $this->action->value,
            'score' => $this->score,
            'band' => $this->band,
            'reason' => $this->reason,
        ];
    }
}
