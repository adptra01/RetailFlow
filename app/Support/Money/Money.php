<?php

namespace App\Support\Money;

/**
 * Immutable money value stored as integer minor units (plan §17.1).
 *
 * IDR has a minor exponent of 0, so one minor unit equals one rupiah.
 * The exponent comes from config so other currencies keep working.
 */
final class Money
{
    public function __construct(private readonly int $minor) {}

    public static function fromMajor(int|float|string $major): self
    {
        $exponent = (int) config('pos.currency.minor_exponent', 0);

        return new self((int) round((float) $major * 10 ** $exponent));
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function minor(): int
    {
        return $this->minor;
    }

    public function major(): float
    {
        $exponent = (int) config('pos.currency.minor_exponent', 0);

        return $this->minor / 10 ** $exponent;
    }

    public function add(self $other): self
    {
        return new self($this->minor + $other->minor);
    }

    public function subtract(self $other): self
    {
        return new self($this->minor - $other->minor);
    }

    public function multiply(int $factor): self
    {
        return new self($this->minor * $factor);
    }
}
