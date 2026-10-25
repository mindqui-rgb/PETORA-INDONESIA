<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class InventoryAdjustment extends Model
{
    use HasUuids;

    protected $fillable = [
        'branch_id',
        'location_id',
        'product_id',
        'reason_code',
        'note',
        'actor_id',
    ];
}
