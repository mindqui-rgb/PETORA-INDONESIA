<?php

declare(strict_types=1);

namespace App\Domain;

enum AppointmentStatus: string
{
    case Scheduled = 'SCHEDULED';
    case CheckedIn = 'CHECKED_IN';
    case InConsultation = 'IN_CONSULTATION';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
    case NoShow = 'NO_SHOW';
}