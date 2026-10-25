<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class Sale extends Model
{
    use HasUuids;

    protected $fillable = [
        'branch_id',
        'shift_id',
        'customer_id',
        'invoice_number',
        'status',
        'subtotal',
        'discount_total',
        'grand_total',
        'created_by',
        'updated_by',
        'completed_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
