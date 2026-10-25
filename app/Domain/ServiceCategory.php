<?php

declare(strict_types=1);

namespace App\Domain;

enum ServiceCategory: string
{
    case Grooming = 'GROOMING';
    case Boarding = 'BOARDING';
    case Clinic = 'CLINIC';
}