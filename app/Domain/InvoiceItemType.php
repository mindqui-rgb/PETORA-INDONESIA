<?php

declare(strict_types=1);

namespace App\Domain;

enum InvoiceItemType: string
{
    case Consultation = 'CONSULTATION';
    case Treatment = 'TREATMENT';
    case Medication = 'MEDICATION';
    case Supply = 'SUPPLY';
    case BoardingDay = 'BOARDING_DAY';
    case Grooming = 'GROOMING';
    case Addon = 'ADDON';
    case Other = 'OTHER';
}