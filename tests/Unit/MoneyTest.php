<?php

use App\Support\Money;

it('keeps decimal precision until rupiah rounding is requested', function () {
    $lineGross = Money::of('9999')->multipliedBy('0.125');

    expect($lineGross->toString())->toBe('1249.875')
        ->and($lineGross->roundedToRupiah()->toString())->toBe('1250');
});

it('rounds rupiah using half up', function () {
    expect(Money::of('1049.50')->roundedToRupiah()->toString())->toBe('1050')
        ->and(Money::of('1049.49')->roundedToRupiah()->toString())->toBe('1049');
});

it('stores monetary values with two decimal places', function () {
    expect(Money::of('1234.567')->toDatabaseString())->toBe('1234.57');
});

it('calculates percentages without floating point arithmetic', function () {
    expect(Money::of('99.99')->percentageOf('10')->toDatabaseString())->toBe('10.00');
});

it('supports comparisons and scale-based division for monetary math', function () {
    $base = Money::of('2500');
    $other = Money::of('2500.00');
    $half = Money::of('10')->dividedBy('4', 2);

    expect($base->equals($other))
        ->toBeTrue()
        ->and($base->greaterThan(Money::of('2499.99')))->toBeTrue()
        ->and($base->isPositive())->toBeTrue()
        ->and($half->toDatabaseString())->toBe('2.50');
});
