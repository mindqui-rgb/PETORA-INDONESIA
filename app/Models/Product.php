<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\ProductCategory;
use App\Domain\ProductUnitType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Product extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'sku',
        'name',
        'category',
        'unit_type',
        'base_unit',
        'track_lot',
        'is_prescription',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'category' => ProductCategory::class,
            'unit_type' => ProductUnitType::class,
            'track_lot' => 'boolean',
            'is_prescription' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
