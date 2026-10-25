<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Domain\Permission;
use App\Domain\ProductCategory;
use App\Domain\ProductUnitType;
use App\Models\Product;
use App\Models\ProductBranchSetting;
use App\Models\ProductPrice;
use App\Models\User;
use App\Services\Shared\AuditLogger;
use App\Services\Shared\Transactions;
use App\Support\AccessControl;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class ProductCatalogService
{
    public function __construct(
        private readonly AccessControl $accessControl,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function createProduct(
        User $actor,
        string $sku,
        string $name,
        ProductCategory $category,
        ProductUnitType $unitType,
        string $baseUnit,
        bool $trackLot = false,
        bool $isPrescription = false,
    ): Product {
        $this->authorize($actor, Permission::ProductManage);

        if (trim($sku) === '' || trim($name) === '' || trim($baseUnit) === '') {
            throw ValidationException::withMessages(['product' => 'SKU, nama, dan satuan wajib diisi.']);
        }

        return Transactions::run(function () use ($actor, $sku, $name, $category, $unitType, $baseUnit, $trackLot, $isPrescription): Product {
            $product = Product::query()->create([
                'sku' => trim($sku),
                'name' => trim($name),
                'category' => $category,
                'unit_type' => $unitType,
                'base_unit' => strtoupper(trim($baseUnit)),
                'track_lot' => $category === ProductCategory::Medicine || $trackLot,
                'is_prescription' => $isPrescription,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            $this->auditLogger->record($actor, 'product.created', $product, details: [
                'sku' => $product->sku,
                'category' => $category->value,
            ]);

            return $product;
        });
    }

    public function configureBranch(
        User $actor,
        string $productId,
        string $branchId,
        bool $isPosSellable,
        bool $isOnlineSellable,
        string $minimumStock = '0',
        bool $isActive = true,
    ): ProductBranchSetting {
        $this->authorize($actor, Permission::ProductManage, $branchId);

        $product = Product::query()->find($productId);

        if ($product === null) {
            throw (new ModelNotFoundException)->setModel(Product::class, [$productId]);
        }

        if ($product->is_prescription && ($isPosSellable || $isOnlineSellable)) {
            throw ValidationException::withMessages([
                'is_pos_sellable' => 'Produk resep tidak boleh dijual melalui POS atau toko online.',
            ]);
        }

        if ($isActive && ($isPosSellable || $isOnlineSellable)
            && $this->activePrice($productId, $branchId) === null) {
            throw ValidationException::withMessages([
                'price' => 'Produk yang aktif dijual wajib memiliki harga efektif di cabang ini.',
            ]);
        }

        $minimum = BigDecimal::of($minimumStock);

        if ($minimum->isNegative()) {
            throw ValidationException::withMessages(['minimum_stock' => 'Stok minimum tidak boleh negatif.']);
        }

        return Transactions::run(function () use ($actor, $product, $branchId, $isPosSellable, $isOnlineSellable, $minimum, $isActive): ProductBranchSetting {
            $settings = ProductBranchSetting::query()->updateOrCreate(
                ['product_id' => $product->getKey(), 'branch_id' => $branchId],
                [
                    'is_pos_sellable' => $isPosSellable,
                    'is_online_sellable' => $isOnlineSellable,
                    'min_stock' => (string) $minimum,
                    'is_active' => $isActive,
                    'created_by' => $product->created_by,
                    'updated_by' => $actor->getKey(),
                ],
            );

            $this->auditLogger->record($actor, 'product.branch_configured', $settings, $branchId, [
                'is_pos_sellable' => $isPosSellable,
                'is_online_sellable' => $isOnlineSellable,
                'is_active' => $isActive,
            ]);

            return $settings;
        });
    }

    public function setBranchPrice(
        User $actor,
        string $productId,
        string $branchId,
        string $amount,
        ?Carbon $effectiveFrom = null,
    ): ProductPrice {
        $this->authorize($actor, Permission::PriceManage, $branchId);

        $price = BigDecimal::of($amount);

        if ($price->isNegative() || $price->stripTrailingZeros()->getScale() > 0) {
            throw ValidationException::withMessages([
                'price' => 'Harga harus berupa rupiah penuh dan tidak boleh negatif.',
            ]);
        }

        if (! Product::query()->whereKey($productId)->exists()) {
            throw (new ModelNotFoundException)->setModel(Product::class, [$productId]);
        }

        return Transactions::run(function () use ($actor, $productId, $branchId, $price, $effectiveFrom): ProductPrice {
            $priceRecord = ProductPrice::query()->create([
                'product_id' => $productId,
                'branch_id' => $branchId,
                'price' => $price->toScale(2)->__toString(),
                'effective_from' => $effectiveFrom ?? now(),
                'created_by' => $actor->getKey(),
                'created_at' => now(),
            ]);

            $this->auditLogger->record($actor, 'product.price_changed', $priceRecord, $branchId, [
                'price' => $priceRecord->price,
                'effective_from' => $priceRecord->effective_from->toIso8601String(),
            ]);

            return $priceRecord;
        });
    }

    public function activePrice(string $productId, string $branchId, ?Carbon $at = null): ?ProductPrice
    {
        return ProductPrice::query()
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->where('effective_from', '<=', $at ?? now())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    private function authorize(User $actor, Permission $permission, ?string $branchId = null): void
    {
        if (! $this->accessControl->can($actor, $permission, $branchId)) {
            throw new AuthorizationException('Pengguna tidak memiliki izin untuk operasi ini.');
        }
    }
}
