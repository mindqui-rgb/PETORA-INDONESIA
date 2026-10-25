<?php

declare(strict_types=1);

namespace App\Domain;

enum PaymentKind: string
{
    case Order = 'ORDER';
    case Additional = 'ADDITIONAL';
}