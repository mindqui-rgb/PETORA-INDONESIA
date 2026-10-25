<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class PurchaseOrder extends Model
{
    use HasUuids;

    protected $fillable = [
        'branch_id',
        'supplier_id',
        'purchase_number',
        'status',
        'notes',
        'created_by',
        'submitted_by',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return ['submitted_at' => 'immutable_datetime'];
    }
}
