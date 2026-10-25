<?php

declare(strict_types=1);

namespace App\Domain;

enum PaymentStatus: string
{
    case Submitted = 'SUBMITTED';
    case Confirmed = 'CONFIRMED';
    case Rejected = 'REJECTED';
}