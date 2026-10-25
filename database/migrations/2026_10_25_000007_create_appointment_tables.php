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
        Schema::create('doctor_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('doctor_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedSmallInteger('slot_minutes');
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['doctor_id', 'branch_id', 'day_of_week', 'is_active']);
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pet_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('doctor_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $table->string('status', 24)->default('SCHEDULED');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->foreignUuid('booked_by')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'starts_at', 'status']);
            $table->index(['pet_id', 'starts_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement('ALTER TABLE doctor_schedules ADD CONSTRAINT doctor_schedules_day_check CHECK (day_of_week BETWEEN 0 AND 6 AND ends_at > starts_at AND slot_minutes > 0)');
            DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_status_check CHECK (status IN ('SCHEDULED','CHECKED_IN','IN_CONSULTATION','COMPLETED','CANCELLED','NO_SHOW'))");
            DB::statement('ALTER TABLE appointments ADD CONSTRAINT appointments_time_check CHECK (ends_at > starts_at)');
            DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_doctor_no_overlap EXCLUDE USING gist (doctor_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&) WHERE (status IN ('SCHEDULED','CHECKED_IN','IN_CONSULTATION'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('doctor_schedules');
    }
};
