<?php

declare(strict_types=1);

namespace App\Domain;

enum Role: string
{
    case Owner = 'OWNER';
    case Doctor = 'DOCTOR';
    case Admin = 'ADMIN';
    case Customer = 'CUSTOMER';
}
