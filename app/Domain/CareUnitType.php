<?php

declare(strict_types=1);

namespace App\Domain;

enum CareUnitType: string
{
    case Kennel = 'KENNEL';
    case Icu = 'ICU';
    case Isolation = 'ISOLATION';
    case BoardingRoom = 'BOARDING_ROOM';
}