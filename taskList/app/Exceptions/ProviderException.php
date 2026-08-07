<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown when a payment provider is unreachable, misconfigured, or errors. */
class ProviderException extends RuntimeException
{
}
