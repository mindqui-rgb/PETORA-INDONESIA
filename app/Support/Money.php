<?php

declare(strict_types=1);

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class Money
{
    private function __construct(private BigDecimal $amount) {}

    public static function of(string|int|BigDecimal $amount): self
    {
        return new self(BigDecimal::of($amount));
    }

    public static function zero(): self
    {
        return self::of(0);
    }

    public function plus(self $other): self
    {
        return new self($this->amount->plus($other->amount));
    }

    public function minus(self $other): self
    {
        return new self($this->amount->minus($other->amount));
    }

    public function multipliedBy(string|int|BigDecimal $factor): self
    {
        return new self($this->amount->multipliedBy($factor));
    }

    public function dividedBy(string|int|BigDecimal $divisor, int $scale = 2): self
    {
        return new self($this->amount->dividedBy($divisor, $scale, RoundingMode::HALF_UP));
    }

    public function percentageOf(string|int|BigDecimal $percentage, int $scale = 2): self
    {
        return new self(
            $this->amount
                ->multipliedBy($percentage)
                ->dividedBy(100, $scale, RoundingMode::HALF_UP)
        );
    }

    public function roundedToRupiah(): self
    {
        return new self($this->amount->toScale(0, RoundingMode::HALF_UP));
    }

    public function toScale(int $scale, RoundingMode $roundingMode = RoundingMode::HALF_UP): self
    {
        return new self($this->amount->toScale($scale, $roundingMode));
    }

    public function compareTo(self $other): int
    {
        return $this->amount->compareTo($other->amount);
    }

    public function equals(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount->isPositive();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function greaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        return $this->compareTo($other) >= 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function lessThanOrEqual(self $other): bool
    {
        return $this->compareTo($other) <= 0;
    }

    public function toDatabaseString(): string
    {
        return (string) $this->amount->toScale(2, RoundingMode::HALF_UP);
    }

    public function toString(): string
    {
        return (string) $this->amount;
    }
}
