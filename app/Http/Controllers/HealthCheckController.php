<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

final class HealthCheckController
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('SELECT 1');

            $healthy = DB::table('jobs')->count() <= 500;
            $privatePath = config('filesystems.disks.private.root');
            $healthy = $healthy
                && is_string($privatePath)
                && is_dir($privatePath)
                && is_writable($privatePath);
        } catch (Throwable) {
            $healthy = false;
        }

        return response()->json(
            ['status' => $healthy ? 'ok' : 'unavailable'],
            $healthy ? 200 : 503,
        );
    }
}
