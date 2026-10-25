<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ExpireIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:expire';

    protected $description = 'Hapus kunci idempotensi yang sudah melewati masa berlaku.';

    public function handle(): int
    {
        $deleted = DB::table('idempotency_keys')
            ->where('expires_at', '<=', now())
            ->delete();

        $this->info("Kunci idempotensi dihapus: {$deleted}.");

        return self::SUCCESS;
    }
}
