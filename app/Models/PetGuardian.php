<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class PetGuardian extends Model
{
    use HasUuids;

    protected $fillable = [
        'pet_id',
        'customer_id',
        'is_primary',
        'started_at',
        'ended_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }
}
