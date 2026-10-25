<?php

declare(strict_types=1);

namespace App\Domain;

enum ReservationStatus: string
{
    case Active = 'ACTIVE';
    case Released = 'RELEASED';
    case Fulfilled = 'FULFILLED';
}