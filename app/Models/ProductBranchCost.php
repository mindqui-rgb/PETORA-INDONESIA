<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ProductBranchCost extends Model
{
    use HasUuids;

    protected $fillable = [
        'product_id',
        'branch_id',
        'avg_unit_cost',
    ];

    protected function casts(): array
    {
        return ['avg_unit_cost' => 'decimal:4'];
    }
}
