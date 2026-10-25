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
        Schema::create('care_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->string('type', 24);
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'code']);
        });

        Schema::create('hospitalizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pet_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('visit_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('admitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('discharged_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 24)->default('PLANNED');
            $table->timestampTz('admitted_at')->nullable();
            $table->timestampTz('discharge_pending_at')->nullable();
            $table->timestampTz('discharged_at')->nullable();
            $table->text('chief_complaint')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status', 'pet_id']);
        });

        Schema::create('hospitalization_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hospitalization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('care_unit_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('start_at');
            $table->timestampTz('end_at')->nullable();
            $table->timestamps();
            $table->index(['hospitalization_id', 'start_at']);
        });

        Schema::create('care_observations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hospitalization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('visit_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('observation_type', 30);
            $table->timestampTz('observed_at');
            $table->json('values')->nullable();
            $table->text('note')->nullable();
            $table->foreignUuid('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['hospitalization_id', 'observed_at']);
        });

        Schema::create('care_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hospitalization_id')->constrained()->restrictOnDelete();
            $table->string('task_type', 40);
            $table->text('description');
            $table->timestampTz('due_at');
            $table->string('status', 16)->default('OPEN');
            $table->foreignUuid('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('done_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('done_at')->nullable();
            $table->timestamps();
            $table->index(['hospitalization_id', 'status', 'due_at']);
        });

        Schema::create('shared_updates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('hospitalization_id')->constrained()->restrictOnDelete();
            $table->string('update_type', 30);
            $table->text('body');
            $table->timestampTz('published_at');
            $table->foreignUuid('published_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['hospitalization_id', 'published_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE care_units ADD CONSTRAINT care_units_type_check CHECK (type IN ('KENNEL','ICU','ISOLATION','BOARDING_ROOM'))");
            DB::statement("ALTER TABLE care_units ADD CONSTRAINT care_units_capacity_check CHECK (capacity >= 1)");
            DB::statement("ALTER TABLE hospitalizations ADD CONSTRAINT hospitalizations_status_check CHECK (status IN ('PLANNED','ADMITTED','DISCHARGE_PENDING','DISCHARGED','CANCELLED'))");
            DB::statement("ALTER TABLE hospitalizations ADD CONSTRAINT hospitalizations_admitted_check CHECK ((status IN ('ADMITTED','DISCHARGE_PENDING','DISCHARGED') AND admitted_at IS NOT NULL) OR (status IN ('PLANNED','CANCELLED')))");
            DB::statement("ALTER TABLE hospitalizations_assignments ADD CONSTRAINT hospitalization_assignments_time_check CHECK (end_at IS NULL OR end_at > start_at)");
            DB::statement("ALTER TABLE care_observations ADD CONSTRAINT care_observations_type_check CHECK (observation_type IN ('VITALS','MEDICATION_GIVEN','FEEDING','URINATION','DEFECATION','PROCEDURE','NOTE'))");
            DB::statement("ALTER TABLE care_tasks ADD CONSTRAINT care_tasks_status_check CHECK (status IN ('OPEN','DONE','CANCELLED'))");
            DB::statement("ALTER TABLE shared_updates ADD CONSTRAINT shared_updates_type_check CHECK (update_type IN ('DAILY_SUMMARY','PROGRESS','DISCHARGE_SUMMARY'))");
            DB::statement("ALTER TABLE shared_updates ADD CONSTRAINT shared_updates_published_check CHECK (published_at IS NOT NULL)");
            DB::statement('CREATE TRIGGER care_observations_append_only BEFORE UPDATE OR DELETE ON care_observations FOR EACH ROW EXECUTE FUNCTION reject_append_only_mutation()');
            DB::statement('CREATE TRIGGER shared_updates_append_only BEFORE UPDATE OR DELETE ON shared_updates FOR EACH ROW EXECUTE FUNCTION reject_append_only_mutation()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS shared_updates_append_only ON shared_updates');
            DB::statement('DROP TRIGGER IF EXISTS care_observations_append_only ON care_observations');
        }

        Schema::dropIfExists('shared_updates');
        Schema::dropIfExists('care_tasks');
        Schema::dropIfExists('care_observations');
        Schema::dropIfExists('hospitalization_assignments');
        Schema::dropIfExists('hospitalizations');
        Schema::dropIfExists('care_units');
    }
};