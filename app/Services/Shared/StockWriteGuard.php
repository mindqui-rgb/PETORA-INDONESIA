<?php

declare(strict_types=1);

namespace App\Services\Shared;

use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final class StockWriteGuard
{
    public static function enable(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new DomainException('TRANSACTION_REQUIRED', 'Perubahan stok harus berada di dalam transaksi.', 500);
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL app.allow_stock_write = 'on'");
        }
    }
}
