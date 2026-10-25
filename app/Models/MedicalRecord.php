<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\MedicalRecordStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class MedicalRecord extends Model
{
    use HasUuids;

    protected $fillable = [
        'visit_id',
        'status',
        'chief_complaint',
        'history',
        'physical_exam',
        'diagnosis',
        'treatment_plan',
        'follow_up_date',
        'clinical_data',
        'internal_note',
        'signed_by',
        'signed_at',
        'doctor_license_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'status' => MedicalRecordStatus::class,
            'follow_up_date' => 'immutable_date',
            'clinical_data' => 'array',
            'signed_at' => 'immutable_datetime',
        ];
    }
}
