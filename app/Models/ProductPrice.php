<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ProductPrice extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'branch_id',
        'price',
        'effective_from',
        'created_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'effective_from' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
