<?php

declare(strict_types=1);

namespace App\Domain;

enum CareObservationType: string
{
    case Vitals = 'VITALS';
    case MedicationGiven = 'MEDICATION_GIVEN';
    case Feeding = 'FEEDING';
    case Urination = 'URINATION';
    case Defecation = 'DEFECATION';
    case Procedure = 'PROCEDURE';
    case Note = 'NOTE';
}