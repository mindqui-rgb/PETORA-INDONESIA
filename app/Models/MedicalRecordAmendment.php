<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class MedicalRecordAmendment extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'medical_record_id',
        'reason',
        'previous_content',
        'new_content',
        'amended_by',
        'amended_at',
    ];

    protected function casts(): array
    {
        return [
            'previous_content' => 'array',
            'new_content' => 'array',
            'amended_at' => 'immutable_datetime',
        ];
    }
}
