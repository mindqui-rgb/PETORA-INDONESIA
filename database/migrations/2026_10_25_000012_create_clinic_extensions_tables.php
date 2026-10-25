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
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('hospitalization_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('patient_pet_id')->nullable()->constrained('pets')->restrictOnDelete();
            $table->foreignUuid('doctor_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('DRAFT');
            $table->text('diagnosis')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('signed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('signed_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['visit_id', 'status']);
        });

        Schema::create('prescription_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('prescription_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->text('dosage');
            $table->text('frequency');
            $table->unsignedSmallInteger('duration_days')->default(0);
            $table->decimal('quantity', 14, 3);
            $table->text('instructions')->nullable();
            $table->timestamps();
        });

        Schema::create('treatment_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('hospitalization_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('service_id')->nullable()->constrained('service_catalog')->restrictOnDelete();
            $table->foreignUuid('doctor_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->string('treatment_type', 30);
            $table->text('description');
            $table->decimal('unit_price', 14, 2);
            $table->decimal('quantity', 14, 2)->default(1);
            $table->decimal('line_total', 14, 2);
            $table->timestampTz('performed_at');
            $table->string('consent_method')->nullable();
            $table->string('consent_given_by_name')->nullable();
            $table->timestamps();
            $table->index(['visit_id', 'performed_at']);
        });

        Schema::create('medication_dispensings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('hospitalization_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('prescription_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignUuid('lot_id')->constrained('inventory_lots')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost_snapshot', 14, 4);
            $table->decimal('unit_price_snapshot', 14, 2);
            $table->string('reason', 20);
            $table->foreignUuid('dispensed_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('dispensed_at')->useCurrent();
            $table->timestamps();
            $table->unique(['visit_id', 'hospitalization_id']);
            $table->index(['product_id', 'dispensed_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_status_check CHECK (status IN ('DRAFT','SIGNED','CANCELLED'))");
            DB::statement("ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_visit_or_hospitalization_check CHECK ((visit_id IS NOT NULL AND hospitalization_id IS NULL) OR (visit_id IS NULL AND hospitalization_id IS NOT NULL))");
            DB::statement("ALTER TABLE prescription_items ADD CONSTRAINT prescription_items_quantity_check CHECK (quantity > 0 AND duration_days >= 0)");
            DB::statement("ALTER TABLE treatment_records ADD CONSTRAINT treatment_records_type_check CHECK (treatment_type IN ('CONSULTATION','PROCEDURE','DIAGNOSTIC'))");
            DB::statement("ALTER TABLE treatment_records ADD CONSTRAINT treatment_records_consistency_check CHECK ((visit_id IS NOT NULL AND hospitalization_id IS NULL) OR (visit_id IS NULL AND hospitalization_id IS NOT NULL))");
            DB::statement("ALTER TABLE treatment_records ADD CONSTRAINT treatment_records_line_total_check CHECK (line_total >= 0)");
            DB::statement("ALTER TABLE medication_dispensings ADD CONSTRAINT medication_dispensings_reason_check CHECK (reason IN ('PRESCRIPTION','PROCEDURE','WARD'))");
            DB::statement("ALTER TABLE medication_dispensings ADD CONSTRAINT medication_dispensings_quantity_check CHECK (quantity > 0)");
            DB::statement("ALTER TABLE medication_dispensings ADD CONSTRAINT medication_dispensings_source_check CHECK ((visit_id IS NOT NULL AND hospitalization_id IS NULL) OR (visit_id IS NULL AND hospitalization_id IS NOT NULL))");
            DB::statement('CREATE TRIGGER medication_dispensings_append_only BEFORE UPDATE OR DELETE ON medication_dispensings FOR EACH ROW EXECUTE FUNCTION reject_append_only_mutation()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS medication_dispensings_append_only ON medication_dispensings');
        }

        Schema::dropIfExists('medication_dispensings');
        Schema::dropIfExists('treatment_records');
        Schema::dropIfExists('prescription_items');
        Schema::dropIfExists('prescriptions');
    }
};