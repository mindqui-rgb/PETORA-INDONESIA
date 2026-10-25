<?php

declare(strict_types=1);

namespace App\Domain;

enum MovementDirection: string
{
    case In = 'IN';
    case Out = 'OUT';
}