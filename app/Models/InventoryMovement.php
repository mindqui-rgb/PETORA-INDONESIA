<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class InventoryMovement extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'location_id',
        'lot_id',
        'quantity_delta',
        'movement_type',
        'unit_cost_snapshot',
        'reference_type',
        'reference_id',
        'reason_code',
        'actor_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'decimal:3',
            'unit_cost_snapshot' => 'decimal:4',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
