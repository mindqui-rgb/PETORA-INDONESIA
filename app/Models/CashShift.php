<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class CashShift extends Model
{
    use HasUuids;

    protected $fillable = [
        'branch_id',
        'cashier_id',
        'status',
        'opening_cash',
        'closing_cash_counted',
        'expected_cash',
        'difference',
        'difference_reason',
        'created_by',
        'closed_by',
        'opened_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'opening_cash' => 'decimal:2',
            'closing_cash_counted' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'difference' => 'decimal:2',
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }
}
