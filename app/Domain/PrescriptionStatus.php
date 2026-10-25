<?php

declare(strict_types=1);

namespace App\Domain;

enum PrescriptionStatus: string
{
    case Draft = 'DRAFT';
    case Signed = 'SIGNED';
    case Cancelled = 'CANCELLED';
}