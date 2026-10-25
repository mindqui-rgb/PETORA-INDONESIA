<?php

declare(strict_types=1);

namespace App\Domain;

enum MedicalRecordStatus: string
{
    case Draft = 'DRAFT';
    case Signed = 'SIGNED';
    case Amended = 'AMENDED';
    case Voided = 'VOIDED';
}
