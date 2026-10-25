<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class StockBalance extends Model
{
    use HasUuids;

    protected $fillable = [
        'product_id',
        'location_id',
        'lot_id',
        'on_hand',
        'reserved',
    ];

    protected function casts(): array
    {
        return [
            'on_hand' => 'decimal:3',
            'reserved' => 'decimal:3',
        ];
    }
}
