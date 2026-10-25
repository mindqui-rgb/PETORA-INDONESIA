<?php

declare(strict_types=1);

namespace App\Services\Shared;

use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use PDOException;
use Throwable;

final class Transactions
{
    public static function run(callable $callback, string $scope = 'business', int $timeoutSeconds = 5): mixed
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return DB::transaction(function () use ($callback, $timeoutSeconds): mixed {
                    if (DB::getDriverName() === 'pgsql') {
                        $timeoutMilliseconds = max(1, $timeoutSeconds) * 1000;
                        DB::statement("SET LOCAL statement_timeout = '{$timeoutMilliseconds}ms'");
                    }

                    return $callback();
                });
            } catch (Throwable $exception) {
                if (! self::isRetryable($exception)) {
                    throw $exception;
                }

                if ($attempt === 3) {
                    throw new DomainException(
                        'CONFLICT',
                        "Transaksi {$scope} gagal setelah percobaan ulang.",
                        409,
                    );
                }

                usleep(random_int(50_000, 150_000));
            }
        }

        throw new DomainException('CONFLICT', "Transaksi {$scope} gagal.", 409);
    }

    private static function isRetryable(Throwable $exception): bool
    {
        if ($exception instanceof DomainException) {
            return false;
        }

        do {
            if ($exception instanceof PDOException) {
                $state = $exception->errorInfo[0] ?? $exception->getCode();

                if (in_array((string) $state, ['40001', '40P01'], true)) {
                    return true;
                }
            }

            $state = (string) $exception->getCode();

            if (in_array($state, ['40001', '40P01'], true)) {
                return true;
            }

            $exception = $exception->getPrevious();
        } while ($exception !== null);

        return false;
    }
}
