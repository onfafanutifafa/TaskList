<?php

namespace App\Enums;

/** What the fraud engine recommends for a transaction. */
enum RiskAction: string
{
    case Allow = 'allow';
    case Review = 'review';
    case Block = 'block';

    public function isBlocked(): bool
    {
        return $this === self::Block;
    }
}
