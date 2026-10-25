<?php

declare(strict_types=1);

namespace App\Domain;

enum ConsentMethod: string
{
    case InPerson = 'IN_PERSON';
    case Phone = 'PHONE';
}