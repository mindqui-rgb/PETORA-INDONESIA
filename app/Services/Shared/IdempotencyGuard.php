<?php

declare(strict_types=1);

namespace App\Services\Shared;

use App\Models\User;
use App\Support\Errors\DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

final class IdempotencyGuard
{
    /** @template T of Model
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(
        User $user,
        string $scope,
        string $key,
        string $requestHash,
        callable $callback,
    ): Model {
        if (! Str::isUuid($key, 4)) {
            throw new DomainException('VALIDATION_ERROR', 'Kunci idempotensi harus UUID v4.', 422);
        }

        return Transactions::run(function () use ($user, $scope, $key, $requestHash, $callback): Model {
            $now = now();
            $existing = DB::table('idempotency_keys')
                ->where('user_id', $user->getKey())
                ->where('scope', $scope)
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->expires_at <= $now) {
                DB::table('idempotency_keys')->where('id', $existing->id)->delete();
            }

            $inserted = DB::table('idempotency_keys')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'user_id' => $user->getKey(),
                'scope' => $scope,
                'key' => $key,
                'request_hash' => $requestHash,
                'status' => 'IN_PROGRESS',
                'result_type' => null,
                'result_id' => null,
                'created_at' => $now,
                'expires_at' => $now->copy()->addHours(24),
            ]);

            $entry = DB::table('idempotency_keys')
                ->where('user_id', $user->getKey())
                ->where('scope', $scope)
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            if ($entry === null) {
                throw new DomainException('CONFLICT', 'Permintaan tidak dapat dikunci.');
            }

            if ($inserted === 0) {
                if (! hash_equals($entry->request_hash, $requestHash)) {
                    throw new DomainException('IDEMPOTENCY_CONFLICT', 'Kunci idempotensi digunakan untuk payload berbeda.');
                }

                if ($entry->status === 'IN_PROGRESS') {
                    throw new DomainException('CONFLICT', 'Permintaan sedang diproses, coba lagi sebentar.');
                }

                return $this->resolveResult($entry->result_type, $entry->result_id);
            }

            try {
                $result = $callback();
            } catch (Throwable $exception) {
                throw $exception;
            }

            if (! $result instanceof Model || $result->getKey() === null) {
                throw new DomainException('INVALID_RESULT', 'Operasi idempoten harus mengembalikan model tersimpan.', 500);
            }

            DB::table('idempotency_keys')->where('id', $entry->id)->update([
                'status' => 'DONE',
                'result_type' => $result::class,
                'result_id' => $result->getKey(),
            ]);

            return $result;
        }, $scope, 5);
    }

    /** @param array<string|int, mixed> $payload
     * @throws JsonException
     */
    public static function hashPayload(array $payload): string
    {
        return hash('sha256', json_encode(self::canonicalize($payload), JSON_THROW_ON_ERROR));
    }

    /** @param array<string|int, mixed> $payload
     * @return array<string|int, mixed>
     */
    private static function canonicalize(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = self::canonicalize($value);
            }
        }

        if (! array_is_list($payload)) {
            ksort($payload);
        }

        return $payload;
    }

    private function resolveResult(?string $resultType, ?string $resultId): Model
    {
        if ($resultType === null || $resultId === null || ! is_subclass_of($resultType, Model::class)) {
            throw new DomainException('INVALID_RESULT', 'Hasil permintaan idempoten tidak valid.', 500);
        }

        return $resultType::query()->findOrFail($resultId);
    }
}
