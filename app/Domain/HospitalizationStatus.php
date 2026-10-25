<?php

declare(strict_types=1);

namespace App\Domain;

enum HospitalizationStatus: string
{
    case Planned = 'PLANNED';
    case Admitted = 'ADMITTED';
    case DischargePending = 'DISCHARGE_PENDING';
    case Discharged = 'DISCHARGED';
    case Cancelled = 'CANCELLED';
}