<?php

declare(strict_types=1);

namespace App\Domain;

enum InventoryAdjustmentReason: string
{
    case CountCorrection = 'COUNT_CORRECTION';
    case Damaged = 'DAMAGED';
    case ExpiryWriteOff = 'EXPIRY_WRITE_OFF';
    case Lost = 'LOST';
    case Found = 'FOUND';
    case ClinicUseCorrection = 'CLINIC_USE_CORRECTION';
    case Other = 'OTHER';
}
