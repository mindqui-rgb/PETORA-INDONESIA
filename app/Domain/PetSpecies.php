<?php

declare(strict_types=1);

namespace App\Domain;

enum PetSpecies: string
{
    case Dog = 'DOG';
    case Cat = 'CAT';
    case Bird = 'BIRD';
    case Rabbit = 'RABBIT';
    case Reptile = 'REPTILE';
    case Other = 'OTHER';
}
