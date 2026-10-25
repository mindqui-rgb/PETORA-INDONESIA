<?php

declare(strict_types=1);

namespace App\Domain;

enum MedicationDispenseReason: string
{
    case Prescription = 'PRESCRIPTION';
    case Procedure = 'PROCEDURE';
    case Ward = 'WARD';
}