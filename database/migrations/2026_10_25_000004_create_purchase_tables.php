<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('tax_id', 40)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $table->string('purchase_number')->unique();
            $table->string('status', 24)->default('DRAFT');
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status', 'created_at']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_ordered', 14, 3);
            $table->decimal('quantity_received', 14, 3)->default(0);
            $table->decimal('unit_cost', 14, 4);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index(['purchase_order_id', 'product_id']);
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('received_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('received_at')->useCurrent();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('goods_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('purchase_order_item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignUuid('lot_id')->constrained('inventory_lots')->restrictOnDelete();
            $table->decimal('quantity_received', 14, 3);
            $table->decimal('unit_cost', 14, 4);
            $table->timestampTz('created_at')->useCurrent();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_status_check CHECK (status IN ('DRAFT','SUBMITTED','PARTIALLY_RECEIVED','RECEIVED','CANCELLED'))");
            DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT purchase_order_items_quantity_check CHECK (quantity_ordered > 0 AND quantity_received >= 0 AND quantity_received <= quantity_ordered AND unit_cost >= 0)');
            DB::statement('ALTER TABLE goods_receipt_items ADD CONSTRAINT goods_receipt_items_amount_check CHECK (quantity_received > 0 AND unit_cost >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
