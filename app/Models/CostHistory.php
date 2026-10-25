<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class CostHistory extends Model
{
    use HasUuids;

    protected $table = 'cost_history';

    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'branch_id',
        'old_cost',
        'new_cost',
        'reference_type',
        'reference_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_cost' => 'decimal:4',
            'new_cost' => 'decimal:4',
            'created_at' => 'immutable_datetime',
        ];
    }
}
