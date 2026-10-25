<?php

declare(strict_types=1);

namespace App\Domain;

enum FinancialPeriodStatus: string
{
    case Open = 'OPEN';
    case Closed = 'CLOSED';
}