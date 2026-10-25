<?php

declare(strict_types=1);

namespace App\Domain;

enum SupplierReturnStatus: string
{
    case Requested = 'REQUESTED';
    case Approved = 'APPROVED';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
}