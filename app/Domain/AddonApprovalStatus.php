<?php

declare(strict_types=1);

namespace App\Domain;

enum AddonApprovalStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Declined = 'DECLINED';
}