<?php

declare(strict_types=1);

namespace App\Domain;

enum FulfillmentStatus: string
{
    case NotStarted = 'NOT_STARTED';
    case StockReview = 'STOCK_REVIEW';
    case Processing = 'PROCESSING';
    case ReadyForPickup = 'READY_FOR_PICKUP';
    case Dispatched = 'DISPATCHED';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
}