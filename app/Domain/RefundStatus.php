<?php

declare(strict_types=1);

namespace App\Domain;

enum RefundStatus: string
{
    case Requested = 'REQUESTED';
    case Approved = 'APPROVED';
    case Completed = 'COMPLETED';
    case Rejected = 'REJECTED';
}