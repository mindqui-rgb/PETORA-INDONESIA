<?php

declare(strict_types=1);

namespace App\Domain;

enum ServiceBookingStatus: string
{
    case Requested = 'REQUESTED';
    case Confirmed = 'CONFIRMED';
    case CheckedIn = 'CHECKED_IN';
    case InProgress = 'IN_PROGRESS';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
    case NoShow = 'NO_SHOW';
}