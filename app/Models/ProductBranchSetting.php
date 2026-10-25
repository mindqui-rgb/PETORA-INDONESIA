<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ProductBranchSetting extends Model
{
    use HasUuids;

    protected $fillable = [
        'product_id',
        'branch_id',
        'is_pos_sellable',
        'is_online_sellable',
        'min_stock',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_pos_sellable' => 'boolean',
            'is_online_sellable' => 'boolean',
            'min_stock' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }
}
