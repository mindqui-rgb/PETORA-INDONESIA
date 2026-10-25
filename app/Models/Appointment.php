<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class Appointment extends Model
{
    use HasUuids;

    protected $fillable = [
        'pet_id',
        'doctor_id',
        'branch_id',
        'customer_id',
        'status',
        'starts_at',
        'ends_at',
        'booked_by',
        'reason',
        'cancelled_by',
        'cancellation_reason',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
