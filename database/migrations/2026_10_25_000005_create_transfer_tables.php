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
        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('source_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignUuid('destination_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->string('status', 16)->default('DRAFT');
            $table->text('approval_reason')->nullable();
            $table->text('dispatch_note')->nullable();
            $table->text('receipt_note')->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('dispatched_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('inventory_transfer_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transfer_id')->constrained('inventory_transfers')->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_requested', 14, 3);
            $table->decimal('quantity_sent', 14, 3)->default(0);
            $table->decimal('quantity_received', 14, 3)->default(0);
            $table->decimal('quantity_lost', 14, 3)->default(0);
            $table->text('loss_reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('inventory_transfer_lots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transfer_item_id')->constrained('inventory_transfer_items')->restrictOnDelete();
            $table->foreignUuid('source_lot_id')->constrained('inventory_lots')->restrictOnDelete();
            $table->foreignUuid('destination_lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();
            $table->decimal('quantity_sent', 14, 3);
            $table->decimal('quantity_received', 14, 3)->default(0);
            $table->decimal('unit_cost', 14, 4);
            $table->text('loss_reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['transfer_item_id', 'source_lot_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE inventory_transfers ADD CONSTRAINT inventory_transfers_status_check CHECK (status IN ('DRAFT','IN_TRANSIT','RECEIVED','CANCELLED'))");
            DB::statement('ALTER TABLE inventory_transfers ADD CONSTRAINT inventory_transfers_locations_check CHECK (source_location_id <> destination_location_id)');
            DB::statement('ALTER TABLE inventory_transfer_items ADD CONSTRAINT inventory_transfer_items_quantity_check CHECK (quantity_requested > 0 AND quantity_sent >= 0 AND quantity_received >= 0 AND quantity_lost >= 0 AND quantity_received + quantity_lost <= quantity_sent)');
            DB::statement('ALTER TABLE inventory_transfer_lots ADD CONSTRAINT inventory_transfer_lots_quantity_check CHECK (quantity_sent > 0 AND quantity_received >= 0 AND quantity_received <= quantity_sent)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_lots');
        Schema::dropIfExists('inventory_transfer_items');
        Schema::dropIfExists('inventory_transfers');
    }
};
