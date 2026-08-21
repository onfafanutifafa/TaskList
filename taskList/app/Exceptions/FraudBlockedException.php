<?php

namespace App\Exceptions;

use App\Services\Fraud\RiskDecision;
use RuntimeException;

/** Thrown when Masenu blocks a transaction before any money moves (renders 422). */
class FraudBlockedException extends RuntimeException
{
    public function __construct(public readonly RiskDecision $decision)
    {
        parent::__construct($decision->reason);
    }
}
