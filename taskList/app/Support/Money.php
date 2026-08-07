<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * An immutable amount of money as an integer count of a currency's MINOR unit
 * (GHS pesewas, KES cents). There is no float anywhere in this class — arithmetic
 * is pure integer math, so it is safe in the payment path.
 */
final class Money
{
    public readonly string $currency;

    public function __construct(
        public readonly int $minor,
        string $currency,
    ) {
        $this->currency = strtoupper($currency);

        if (! array_key_exists($this->currency, config('psp.currencies.minor_units'))) {
            throw new InvalidArgumentException("Unsupported currency [{$currency}].");
        }
    }

    public static function of(int $minor, string $currency): self
    {
        return new self($minor, $currency);
    }

    /** Build from a major-unit decimal string ("12.50" GHS -> 1250). String in, no float. */
    public static function fromMajor(string $major, string $currency): self
    {
        $currency = strtoupper($currency);
        $factor = self::factorFor($currency);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $major)) {
            throw new InvalidArgumentException("Malformed amount [{$major}].");
        }

        [$whole, $frac] = array_pad(explode('.', $major), 2, '');
        $frac = str_pad(substr($frac, 0, strlen((string) ($factor - 1))), max(0, strlen((string) $factor) - 1), '0');
        $sign = str_starts_with($whole, '-') ? -1 : 1;
        $minor = ((int) ltrim($whole, '-')) * $factor + ($frac === '' ? 0 : (int) $frac);

        return new self($sign * $minor, $currency);
    }

    private static function factorFor(string $currency): int
    {
        return (int) (config("psp.currencies.minor_units.{$currency}")
            ?? throw new InvalidArgumentException("Unsupported currency [{$currency}]."));
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    /** Take $bps basis points of this amount, rounded half-up, floored at 0. */
    public function feeAtBps(int $bps): self
    {
        $fee = intdiv($this->minor * $bps + 5000, 10000);

        return new self(max(0, $fee), $this->currency);
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor > $other->minor;
    }

    public function toMajorString(): string
    {
        $factor = self::factorFor($this->currency);

        if ($factor === 1) {
            return (string) $this->minor;
        }

        $sign = $this->minor < 0 ? '-' : '';
        $abs = abs($this->minor);
        $digits = strlen((string) $factor) - 1;

        return sprintf('%s%d.%0'.$digits.'d', $sign, intdiv($abs, $factor), $abs % $factor);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Currency mismatch: {$this->currency} vs {$other->currency}."
            );
        }
    }
}
