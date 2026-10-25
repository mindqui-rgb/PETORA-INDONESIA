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
        Schema::create('service_catalog', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('category', 20);
            $table->unsignedSmallInteger('duration_minutes')->default(0);
            $table->boolean('is_addon')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('service_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_id')->constrained('service_catalog')->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->decimal('price', 14, 2);
            $table->timestampTz('effective_from');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['service_id', 'branch_id', 'effective_from']);
        });

        Schema::create('service_capacity', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('service_id')->constrained('service_catalog')->restrictOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->timestamps();
            $table->unique(['branch_id', 'service_id', 'starts_at', 'ends_at']);
        });

        Schema::create('service_bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('pet_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('service_id')->constrained('service_catalog')->restrictOnDelete();
            $table->string('type', 20);
            $table->string('status', 24)->default('REQUESTED');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->foreignUuid('booked_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status', 'starts_at']);
        });

        Schema::create('service_booking_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_booking_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('service_id')->constrained('service_catalog')->restrictOnDelete();
            $table->decimal('quantity', 14, 2)->default(1);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();
        });

        Schema::create('boarding_stays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_booking_id')->constrained()->unique()->restrictOnDelete();
            $table->foreignUuid('care_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('check_in_date');
            $table->date('check_out_date')->nullable();
            $table->timestampTz('checked_in_at')->nullable();
            $table->foreignUuid('checked_in_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('checked_out_at')->nullable();
            $table->foreignUuid('checked_out_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('initial_condition')->nullable();
            $table->text('final_condition')->nullable();
            $table->timestamps();
        });

        Schema::create('service_addon_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_booking_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('service_id')->constrained('service_catalog')->restrictOnDelete();
            $table->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('PENDING');
            $table->string('approval_method')->nullable();
            $table->string('approved_by_name')->nullable();
            $table->text('decline_reason')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE service_catalog ADD CONSTRAINT service_catalog_category_check CHECK (category IN ('GROOMING','BOARDING','CLINIC'))");
            DB::statement("ALTER TABLE service_bookings ADD CONSTRAINT service_bookings_type_check CHECK (type IN ('GROOMING','BOARDING'))");
            DB::statement("ALTER TABLE service_bookings ADD CONSTRAINT service_bookings_status_check CHECK (status IN ('REQUESTED','CONFIRMED','CHECKED_IN','IN_PROGRESS','COMPLETED','CANCELLED','NO_SHOW'))");
            DB::statement("ALTER TABLE service_bookings ADD CONSTRAINT service_bookings_time_check CHECK (ends_at > starts_at)");
            DB::statement("ALTER TABLE service_capacity ADD CONSTRAINT service_capacity_capacity_check CHECK (capacity >= 1 AND ends_at > starts_at)");
            DB::statement("ALTER TABLE service_addon_approvals ADD CONSTRAINT service_addon_approvals_status_check CHECK (status IN ('PENDING','APPROVED','DECLINED'))");
            DB::statement("ALTER TABLE service_addon_approvals ADD CONSTRAINT service_addon_approvals_method_check CHECK (approval_method IS NULL OR approval_method IN ('IN_PERSON','PHONE'))");
            DB::statement('CREATE TRIGGER service_prices_append_only BEFORE UPDATE OR DELETE ON service_prices FOR EACH ROW EXECUTE FUNCTION reject_append_only_mutation()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS service_prices_append_only ON service_prices');
        }

        Schema::dropIfExists('service_addon_approvals');
        Schema::dropIfExists('boarding_stays');
        Schema::dropIfExists('service_booking_items');
        Schema::dropIfExists('service_bookings');
        Schema::dropIfExists('service_capacity');
        Schema::dropIfExists('service_prices');
        Schema::dropIfExists('service_catalog');
    }
};