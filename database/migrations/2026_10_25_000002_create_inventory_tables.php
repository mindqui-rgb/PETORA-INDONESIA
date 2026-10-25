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
        Schema::create('inventory_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->string('type', 24);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'code']);
        });

        Schema::create('inventory_lots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->string('lot_number', 100);
            $table->date('expiry_date')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['product_id', 'location_id', 'lot_number']);
            $table->index(['product_id', 'location_id', 'expiry_date', 'received_at']);
        });

        Schema::create('stock_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignUuid('lot_id')->constrained('inventory_lots')->restrictOnDelete();
            $table->decimal('on_hand', 14, 3)->default(0);
            $table->decimal('reserved', 14, 3)->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'location_id', 'lot_id']);
        });

        Schema::create('inventory_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->string('reason_code', 40);
            $table->text('note')->nullable();
            $table->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignUuid('lot_id')->constrained('inventory_lots')->restrictOnDelete();
            $table->decimal('quantity_delta', 14, 3);
            $table->string('movement_type', 40);
            $table->decimal('unit_cost_snapshot', 14, 4)->nullable();
            $table->string('reference_type', 120);
            $table->uuid('reference_id');
            $table->string('reason_code', 40)->nullable();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->index(['product_id', 'location_id', 'lot_id', 'occurred_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('product_branch_costs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->decimal('avg_unit_cost', 14, 4);
            $table->timestamps();
            $table->unique(['product_id', 'branch_id']);
        });

        Schema::create('cost_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->decimal('old_cost', 14, 4)->nullable();
            $table->decimal('new_cost', 14, 4);
            $table->string('reference_type', 120);
            $table->uuid('reference_id');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['product_id', 'branch_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_balances ADD CONSTRAINT stock_balances_amount_check CHECK (on_hand >= 0 AND reserved >= 0 AND reserved <= on_hand)');
            DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_delta_check CHECK (quantity_delta <> 0)');
            DB::statement("ALTER TABLE inventory_locations ADD CONSTRAINT inventory_locations_type_check CHECK (type IN ('RETAIL','CLINIC_PHARMACY','WAREHOUSE'))");
            DB::statement("CREATE UNIQUE INDEX inventory_locations_one_retail_per_branch ON inventory_locations (branch_id) WHERE type = 'RETAIL'");
            DB::statement("CREATE UNIQUE INDEX inventory_locations_one_clinic_per_branch ON inventory_locations (branch_id) WHERE type = 'CLINIC_PHARMACY'");
            DB::statement("CREATE FUNCTION guard_stock_balance_write() RETURNS trigger AS $$ BEGIN IF current_setting('app.allow_stock_write', true) IS DISTINCT FROM 'on' THEN RAISE EXCEPTION 'stock_balances can only be changed through a stock service'; END IF; RETURN NEW; END; $$ LANGUAGE plpgsql");
            DB::statement('CREATE TRIGGER stock_balances_write_guard BEFORE INSERT OR UPDATE ON stock_balances FOR EACH ROW EXECUTE FUNCTION guard_stock_balance_write()');
            DB::statement('CREATE TRIGGER inventory_movements_append_only BEFORE UPDATE OR DELETE ON inventory_movements FOR EACH ROW EXECUTE FUNCTION reject_append_only_mutation()');
            DB::statement('CREATE TRIGGER cost_history_append_only BEFORE UPDATE OR DELETE ON cost_history FOR EACH ROW EXECUTE FUNCTION reject_append_only_mutation()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS cost_history_append_only ON cost_history');
            DB::statement('DROP TRIGGER IF EXISTS inventory_movements_append_only ON inventory_movements');
            DB::statement('DROP TRIGGER IF EXISTS stock_balances_write_guard ON stock_balances');
            DB::statement('DROP FUNCTION IF EXISTS guard_stock_balance_write()');
            DB::statement('DROP INDEX IF EXISTS inventory_locations_one_clinic_per_branch');
            DB::statement('DROP INDEX IF EXISTS inventory_locations_one_retail_per_branch');
        }

        Schema::dropIfExists('cost_history');
        Schema::dropIfExists('product_branch_costs');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_adjustments');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('inventory_lots');
        Schema::dropIfExists('inventory_locations');
    }
};
