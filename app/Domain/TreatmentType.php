<?php

declare(strict_types=1);

namespace App\Domain;

enum TreatmentType: string
{
    case Consultation = 'CONSULTATION';
    case Procedure = 'PROCEDURE';
    case Diagnostic = 'DIAGNOSTIC';
}