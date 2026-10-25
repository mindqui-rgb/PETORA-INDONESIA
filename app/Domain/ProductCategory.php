<?php

declare(strict_types=1);

namespace App\Domain;

enum ProductCategory: string
{
    case Retail = 'RETAIL';
    case Medicine = 'MEDICINE';
    case ClinicSupply = 'CLINIC_SUPPLY';
    case Food = 'FOOD';
    case Accessory = 'ACCESSORY';
    case Other = 'OTHER';
}
