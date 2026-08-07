<?php

namespace App\Enums;

enum ApiKeyMode: string
{
    case Test = 'test';
    case Live = 'live';

    public function prefix(): string
    {
        return $this === self::Live ? 'sk_live_' : 'sk_test_';
    }
}
