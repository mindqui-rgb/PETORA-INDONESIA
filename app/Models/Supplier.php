<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Supplier extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'contact_name',
        'phone',
        'email',
        'address',
        'tax_id',
        'payment_terms_days',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'payment_terms_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
