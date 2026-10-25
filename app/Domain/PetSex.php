<?php

declare(strict_types=1);

namespace App\Domain;

enum PetSex: string
{
    case Male = 'MALE';
    case Female = 'FEMALE';
    case Unknown = 'UNKNOWN';
}
