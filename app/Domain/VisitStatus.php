<?php

declare(strict_types=1);

namespace App\Domain;

enum VisitStatus: string
{
    case Open = 'OPEN';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
}
