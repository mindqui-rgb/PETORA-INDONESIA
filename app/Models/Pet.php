<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class Pet extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'species',
        'breed',
        'sex',
        'is_neutered',
        'birth_date',
        'birth_date_is_estimate',
        'weight_kg',
        'color',
        'microchip',
        'is_deceased',
        'deceased_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_neutered' => 'boolean',
            'birth_date' => 'immutable_date',
            'birth_date_is_estimate' => 'boolean',
            'weight_kg' => 'decimal:3',
            'is_deceased' => 'boolean',
            'deceased_at' => 'immutable_datetime',
        ];
    }
}
