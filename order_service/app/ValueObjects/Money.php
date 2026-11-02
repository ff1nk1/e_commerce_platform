<?php

declare(strict_types=1);

namespace App\ValueObjects;

use InvalidArgumentException;

final class Money
{
    private const MINOR_UNITS_PER_MAJOR = '100';

    /**
     * @param  numeric-string  $amount
     */
    private function __construct(
        private readonly string $amount,
    ) {}

    public static function of(string|int|float $amount): self
    {
        $raw = is_string($amount) ? trim($amount) : number_format((float) $amount, 2, '.', '');

        return new self(self::normalize($raw));
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
        $raw = self::normalize((string) $factor);

        if (function_exists('bcmul')) {
            return new self(bcmul($this->amount, $raw, 2));
        }

        return new self(self::normalize(number_format((float) $this->amount * (float) $raw, 2, '.', '')));
    }

    public function add(self $other): self
    {
        if (function_exists('bcadd')) {
            return new self(bcadd($this->amount, $other->amount, 2));
        }

        return new self(self::normalize(number_format((float) $this->amount + (float) $other->amount, 2, '.', '')));
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

    /**
     * Validate a raw amount and return it as a numeric string.
     *
     * @return numeric-string
     */
    private static function normalize(string $value): string
    {
        throw_unless(is_numeric($value), InvalidArgumentException::class, 'Invalid money amount: '.$value);

        if (function_exists('bcadd')) {
            return bcadd($value, '0', 2);
        }

        return number_format((float) $value, 2, '.', '');
    }
}
