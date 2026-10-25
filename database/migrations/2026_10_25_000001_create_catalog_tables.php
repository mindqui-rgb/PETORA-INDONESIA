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
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('sku', 80)->unique();
            $table->string('name');
            $table->string('category', 30);
            $table->string('unit_type', 10);
            $table->string('base_unit', 20);
            $table->boolean('track_lot')->default(false);
            $table->boolean('is_prescription')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->string('barcode', 100)->unique();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('product_branch_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->boolean('is_pos_sellable')->default(false);
            $table->boolean('is_online_sellable')->default(false);
            $table->decimal('min_stock', 14, 3)->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['product_id', 'branch_id']);
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->decimal('price', 14, 2);
            $table->timestampTz('effective_from');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['product_id', 'branch_id', 'effective_from']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 100);
            $table->string('subject_type', 120);
            $table->uuid('subject_id')->nullable();
            $table->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('details')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
            $table->index(['actor_id', 'occurred_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE products ADD CONSTRAINT products_category_check CHECK (category IN ('RETAIL','MEDICINE','CLINIC_SUPPLY','FOOD','ACCESSORY','OTHER'))");
            DB::statement("ALTER TABLE products ADD CONSTRAINT products_unit_type_check CHECK (unit_type IN ('COUNT','WEIGHT'))");
            DB::statement("ALTER TABLE products ADD CONSTRAINT products_medicine_lot_check CHECK (category <> 'MEDICINE' OR track_lot = TRUE)");
            DB::statement('ALTER TABLE product_branch_settings ADD CONSTRAINT product_branch_min_stock_check CHECK (min_stock >= 0)');
            DB::statement('ALTER TABLE product_prices ADD CONSTRAINT product_prices_full_rupiah_check CHECK (price >= 0 AND price = trunc(price))');
            DB::statement("CREATE FUNCTION reject_append_only_mutation() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION '% is append-only', TG_TABLE_NAME; END; $$ LANGUAGE plpgsql");
            DB::statement('CREATE TRIGGER audit_logs_append_only BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION reject_append_only_mutation()');
            DB::statement('CREATE TRIGGER product_prices_append_only BEFORE UPDATE OR DELETE ON product_prices FOR EACH ROW EXECUTE FUNCTION reject_append_only_mutation()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS product_prices_append_only ON product_prices');
            DB::statement('DROP TRIGGER IF EXISTS audit_logs_append_only ON audit_logs');
            DB::statement('DROP FUNCTION IF EXISTS reject_append_only_mutation()');
        }

        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('product_branch_settings');
        Schema::dropIfExists('product_barcodes');
        Schema::dropIfExists('products');
    }
};
