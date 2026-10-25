<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'CASH', 'name' => 'Tunai', 'is_cash' => true],
            ['code' => 'TRANSFER', 'name' => 'Transfer bank', 'is_cash' => false],
            ['code' => 'QRIS', 'name' => 'QRIS', 'is_cash' => false],
        ] as $method) {
            DB::table('payment_methods')->insertOrIgnore([
                ...$method,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
