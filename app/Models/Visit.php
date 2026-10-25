<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\VisitStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class Visit extends Model
{
    use HasUuids;

    protected $fillable = [
        'pet_id',
        'branch_id',
        'doctor_id',
        'appointment_id',
        'status',
        'started_at',
        'completed_at',
        'consent_given_by_name',
        'consent_method',
        'weight_kg',
        'temperature_c',
        'heart_rate',
        'respiratory_rate',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => VisitStatus::class,
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'weight_kg' => 'decimal:3',
            'temperature_c' => 'decimal:2',
            'heart_rate' => 'integer',
            'respiratory_rate' => 'integer',
        ];
    }
}
