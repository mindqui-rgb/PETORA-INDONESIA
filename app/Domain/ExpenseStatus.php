<?php

declare(strict_types=1);

namespace App\Domain;

enum ExpenseStatus: string
{
    case PendingApproval = 'PENDING_APPROVAL';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
}