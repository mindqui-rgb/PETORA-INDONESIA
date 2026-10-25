<?php

declare(strict_types=1);

namespace App\Domain;

enum PurchaseOrderStatus: string
{
    case Draft = 'DRAFT';
    case Submitted = 'SUBMITTED';
    case PartiallyReceived = 'PARTIALLY_RECEIVED';
    case Received = 'RECEIVED';
    case Cancelled = 'CANCELLED';
}