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
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->string('code', 30)->primary();
            $table->string('name');
            $table->boolean('is_cash')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('branch_payment_methods', function (Blueprint $table) {
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->string('method_code', 30);
            $table->boolean('is_active')->default(true);
            $table->primary(['branch_id', 'method_code']);
            $table->foreign('method_code')->references('code')->on('payment_methods')->restrictOnDelete();
        });

        Schema::create('cash_shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('cashier_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 12)->default('OPEN');
            $table->decimal('opening_cash', 14, 2)->default(0);
            $table->decimal('closing_cash_counted', 14, 2)->nullable();
            $table->decimal('expected_cash', 14, 2)->nullable();
            $table->decimal('difference', 14, 2)->nullable();
            $table->text('difference_reason')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('opened_at')->useCurrent();
            $table->timestampTz('closed_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status']);
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('shift_id')->nullable()->constrained('cash_shifts')->restrictOnDelete();
            $table->uuid('customer_id')->nullable();
            $table->string('invoice_number')->nullable()->unique();
            $table->string('status', 24)->default('DRAFT');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status', 'created_at']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->string('name_snapshot');
            $table->string('sku_snapshot', 80);
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 14, 2);
            $table->string('discount_type', 12)->default('AMOUNT');
            $table->decimal('discount_value', 14, 2)->default(0);
            $table->decimal('line_gross', 14, 2);
            $table->decimal('line_discount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->foreignUuid('discount_override_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('discount_override_at')->nullable();
            $table->text('discount_override_reason')->nullable();
            $table->decimal('unit_cost_snapshot', 14, 4)->nullable();
            $table->decimal('returned_quantity', 14, 3)->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index(['sale_id', 'product_id']);
        });

        Schema::create('sale_item_lots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('lot_id')->constrained('inventory_lots')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['sale_item_id', 'lot_id']);
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_id')->constrained()->restrictOnDelete();
            $table->string('method_code', 30);
            $table->decimal('amount', 14, 2);
            $table->decimal('tendered_amount', 14, 2);
            $table->decimal('change_amount', 14, 2)->default(0);
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign('method_code')->references('code')->on('payment_methods')->restrictOnDelete();
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('shift_id')->nullable()->constrained('cash_shifts')->restrictOnDelete();
            $table->string('movement_type', 40);
            $table->string('method_code', 30);
            $table->decimal('amount', 14, 2);
            $table->string('direction', 3);
            $table->string('reference_type', 120);
            $table->uuid('reference_id');
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->index(['branch_id', 'method_code', 'occurred_at']);
            $table->index(['shift_id', 'method_code']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->string('document_type', 10);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->unique(['branch_id', 'document_type', 'year']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('scope', 100);
            $table->uuid('key');
            $table->string('request_hash', 64);
            $table->string('status', 16);
            $table->string('result_type', 160)->nullable();
            $table->uuid('result_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('expires_at');
            $table->unique(['user_id', 'scope', 'key']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX cash_shifts_one_open_per_cashier ON cash_shifts (cashier_id) WHERE status = 'OPEN'");
            DB::statement("ALTER TABLE cash_shifts ADD CONSTRAINT cash_shifts_status_check CHECK (status IN ('OPEN','CLOSED'))");
            DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_status_check CHECK (status IN ('DRAFT','COMPLETED','PARTIALLY_RETURNED','RETURNED','VOIDED'))");
            DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_quantity_check CHECK (quantity > 0 AND returned_quantity >= 0 AND returned_quantity <= quantity)');
            DB::statement('ALTER TABLE sale_payments ADD CONSTRAINT sale_payments_amount_check CHECK (amount > 0 AND tendered_amount >= amount AND change_amount = tendered_amount - amount)');
            DB::statement("ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_amount_check CHECK (amount > 0 AND direction IN ('IN','OUT'))");
            DB::statement("ALTER TABLE idempotency_keys ADD CONSTRAINT idempotency_keys_status_check CHECK (status IN ('IN_PROGRESS','DONE'))");
            DB::statement("ALTER TABLE idempotency_keys ADD CONSTRAINT idempotency_keys_result_check CHECK ((status = 'IN_PROGRESS' AND result_type IS NULL AND result_id IS NULL) OR (status = 'DONE' AND result_type IS NOT NULL AND result_id IS NOT NULL))");
            DB::statement('CREATE TRIGGER sale_payments_append_only BEFORE UPDATE OR DELETE ON sale_payments FOR EACH ROW EXECUTE FUNCTION reject_append_only_mutation()');
            DB::statement('CREATE TRIGGER cash_movements_append_only BEFORE UPDATE OR DELETE ON cash_movements FOR EACH ROW EXECUTE FUNCTION reject_append_only_mutation()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS cash_movements_append_only ON cash_movements');
            DB::statement('DROP TRIGGER IF EXISTS sale_payments_append_only ON sale_payments');
            DB::statement('DROP INDEX IF EXISTS cash_shifts_one_open_per_cashier');
        }

        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('invoice_sequences');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sale_item_lots');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('cash_shifts');
        Schema::dropIfExists('branch_payment_methods');
        Schema::dropIfExists('payment_methods');
    }
};
