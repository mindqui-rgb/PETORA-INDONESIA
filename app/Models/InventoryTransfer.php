<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class InventoryTransfer extends Model
{
    use HasUuids;

    protected $fillable = [
        'source_location_id',
        'destination_location_id',
        'status',
        'approval_reason',
        'dispatch_note',
        'receipt_note',
        'created_by',
        'approved_by',
        'dispatched_by',
        'received_by',
        'approved_at',
        'dispatched_at',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }
}
