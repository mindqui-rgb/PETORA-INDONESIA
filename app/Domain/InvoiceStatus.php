<?php

declare(strict_types=1);

namespace App\Domain;

enum InvoiceStatus: string
{
    case Open = 'OPEN';
    case PartiallyPaid = 'PARTIALLY_PAID';
    case Paid = 'PAID';
    case Voided = 'VOIDED';
}