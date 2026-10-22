<?php

namespace App\Domain;

use InvalidArgumentException;

final class Money
{
    private const MINOR_UNITS_PER_MAJOR = '100';

    private function __construct(
        private readonly string $amount,
    ) {}

    public static function of(string|int|float $amount): self
    {
        if (is_string($amount)) {
            $raw = trim($amount);
        } else {
            $raw = number_format((float) $amount, 2, '.', '');
        }

        if ($raw === '' || ! is_numeric($raw)) {
            throw new InvalidArgumentException('Invalid money amount: '.$raw);
        }

        if (function_exists('bcadd')) {
            $normalized = bcadd($raw, '0', 2);
        } else {
            $normalized = number_format((float) $raw, 2, '.', '');
        }

        return new self($normalized);
    }

    public static function zero(): self
    {
        return self::of('0.00');
    }

    public function amount(): string
    {
        return $this->amount;
    }

    public function multiply(string|int $factor): self
    {
        if (function_exists('bcmul')) {
            return new self(bcmul($this->amount, (string) $factor, 2));
        }

        return new self(number_format((float) $this->amount * (float) $factor, 2, '.', ''));
    }

    public function add(self $other): self
    {
        if (function_exists('bcadd')) {
            return new self(bcadd($this->amount, $other->amount, 2));
        }

        return new self(number_format((float) $this->amount + (float) $other->amount, 2, '.', ''));
    }

    public function toMinorUnits(): int
    {
        if (function_exists('bcmul')) {
            return (int) bcmul($this->amount, self::MINOR_UNITS_PER_MAJOR, 0);
        }

        $normalized = number_format((float) $this->amount, 2, '.', '');

        return (int) str_replace('.', '', $normalized);
    }

    public function equals(self $other): bool
    {
        if (function_exists('bccomp')) {
            return bccomp($this->amount, $other->amount, 2) === 0;
        }

        return $this->amount === $other->amount;
    }
}
