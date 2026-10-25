<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class InventoryLot extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'location_id',
        'lot_number',
        'expiry_date',
        'received_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'immutable_date',
            'received_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
