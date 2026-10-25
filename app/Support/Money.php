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

    public function toDatabaseString(): string
    {
        return (string) $this->amount->toScale(2, RoundingMode::HALF_UP);
    }

    public function toString(): string
    {
        return (string) $this->amount;
    }
}
