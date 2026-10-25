<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class GoodsReceipt extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'purchase_order_id',
        'branch_id',
        'received_by',
        'received_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
