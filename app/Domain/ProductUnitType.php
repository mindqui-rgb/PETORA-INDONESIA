<?php

declare(strict_types=1);

namespace App\Domain;

enum ProductUnitType: string
{
    case Count = 'COUNT';
    case Weight = 'WEIGHT';
}
