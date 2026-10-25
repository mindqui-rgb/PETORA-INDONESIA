<?php

declare(strict_types=1);

use App\Domain\ProductCategory;
use App\Domain\ProductUnitType;
use App\Domain\Role;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Shared\DocumentNumberService;
use App\Services\Shared\IdempotencyGuard;
use App\Support\Errors\DomainException;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->owner = User::factory()->create();
    $this->owner->forceFill(['role' => Role::Owner])->save();
    $this->branchId = (string) Str::uuid();
    DB::table('branches')->insert([
        'id' => $this->branchId,
        'code' => 'UTM',
        'name' => 'Cabang Utama',
        'timezone' => 'Asia/Jakarta',
        'delivery_fee' => 0,
        'pos_enabled' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->guard = app(IdempotencyGuard::class);
    $this->numbers = app(DocumentNumberService::class);
});

it('canonicalizes payload keys and replays a completed model without calling back twice', function () {
    $product = app(ProductCatalogService::class)->createProduct(
        $this->owner,
        'SHARED-001',
        'Produk shared',
        ProductCategory::Other,
        ProductUnitType::Count,
        'PCS',
    );
    $key = '423e4567-e89b-42d3-a456-426614174301';
    $callbackCount = 0;

    $first = $this->guard->run(
        $this->owner,
        'test.shared.product',
        $key,
        IdempotencyGuard::hashPayload(['name' => 'Produk shared', 'sku' => 'SHARED-001']),
        function () use ($product, &$callbackCount): Product {
            $callbackCount++;

            return $product;
        },
    );
    $replayed = $this->guard->run(
        $this->owner,
        'test.shared.product',
        $key,
        IdempotencyGuard::hashPayload(['sku' => 'SHARED-001', 'name' => 'Produk shared']),
        function () use (&$callbackCount): Product {
            $callbackCount++;

            return Product::query()->firstOrFail();
        },
    );

    expect($first->getKey())->toBe($product->getKey())
        ->and($replayed->getKey())->toBe($product->getKey())
        ->and($callbackCount)->toBe(1)
        ->and(DB::table('idempotency_keys')->where('key', $key)->value('status'))->toBe('DONE');
});

it('rejects a reused key with a different request hash', function () {
    $product = app(ProductCatalogService::class)->createProduct(
        $this->owner,
        'SHARED-002',
        'Produk kedua',
        ProductCategory::Other,
        ProductUnitType::Count,
        'PCS',
    );
    $key = '423e4567-e89b-42d3-a456-426614174302';
    $this->guard->run($this->owner, 'test.shared.conflict', $key, IdempotencyGuard::hashPayload(['a' => 1]), fn (): Product => $product);

    try {
        $this->guard->run($this->owner, 'test.shared.conflict', $key, IdempotencyGuard::hashPayload(['a' => 2]), fn (): Product => $product);
        test()->fail('Expected an idempotency conflict.');
    } catch (DomainException $exception) {
        expect($exception->errorCode)->toBe('IDEMPOTENCY_CONFLICT')
            ->and($exception->httpStatus)->toBe(409);
    }
});

it('rolls back an in-progress idempotency key when its callback fails', function () {
    $key = '423e4567-e89b-42d3-a456-426614174303';

    try {
        $this->guard->run($this->owner, 'test.shared.failure', $key, IdempotencyGuard::hashPayload([]), function (): never {
            throw new RuntimeException('Simulated operation failure.');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('idempotency_keys')->where('key', $key)->count())->toBe(0);
});

it('allows a key to run again after its 24-hour expiration', function () {
    $product = app(ProductCatalogService::class)->createProduct(
        $this->owner,
        'SHARED-EXPIRED',
        'Produk setelah expiry',
        ProductCategory::Other,
        ProductUnitType::Count,
        'PCS',
    );
    $key = '423e4567-e89b-42d3-a456-426614174304';
    $hash = IdempotencyGuard::hashPayload(['operation' => 'first']);
    $this->guard->run($this->owner, 'test.shared.expiry', $key, $hash, fn (): Product => $product);
    DB::table('idempotency_keys')->where('key', $key)->update(['expires_at' => now()->subSecond()]);
    $callbackCount = 0;

    $result = $this->guard->run(
        $this->owner,
        'test.shared.expiry',
        $key,
        IdempotencyGuard::hashPayload(['operation' => 'second']),
        function () use ($product, &$callbackCount): Product {
            $callbackCount++;

            return $product;
        },
    );

    expect($result->getKey())->toBe($product->getKey())
        ->and($callbackCount)->toBe(1)
        ->and(DB::table('idempotency_keys')->where('key', $key)->value('request_hash'))->toBe(IdempotencyGuard::hashPayload(['operation' => 'second']));
});

it('rolls document sequences back with the surrounding transaction', function () {
    try {
        DB::transaction(function () {
            expect($this->numbers->next($this->branchId, 'POS', 2026))->toBe('UTM-POS-2026-000001');
            throw new RuntimeException('Simulated transaction rollback.');
        });
    } catch (RuntimeException) {
    }

    $next = DB::transaction(fn (): string => $this->numbers->next($this->branchId, 'POS', 2026));

    expect($next)->toBe('UTM-POS-2026-000001');
});
