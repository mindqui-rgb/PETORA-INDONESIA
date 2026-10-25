<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class DoctorSchedule extends Model
{
    use HasUuids;

    protected $fillable = [
        'doctor_id',
        'branch_id',
        'day_of_week',
        'starts_at',
        'ends_at',
        'slot_minutes',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'slot_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
