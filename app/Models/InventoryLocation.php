<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class InventoryLocation extends Model
{
    use HasUuids;

    protected $fillable = [
        'branch_id',
        'code',
        'name',
        'type',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
