<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class Customer extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'address',
        'user_id',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
