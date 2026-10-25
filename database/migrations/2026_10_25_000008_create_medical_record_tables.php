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
        Schema::create('visits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pet_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('doctor_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('appointment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('OPEN');
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('completed_at')->nullable();
            $table->string('consent_given_by_name')->nullable();
            $table->string('consent_method', 16)->nullable();
            $table->decimal('weight_kg', 8, 3)->nullable();
            $table->decimal('temperature_c', 5, 2)->nullable();
            $table->unsignedSmallInteger('heart_rate')->nullable();
            $table->unsignedSmallInteger('respiratory_rate')->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'doctor_id', 'started_at']);
        });

        Schema::create('medical_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('DRAFT');
            $table->text('chief_complaint')->nullable();
            $table->text('history')->nullable();
            $table->text('physical_exam')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('treatment_plan')->nullable();
            $table->date('follow_up_date')->nullable();
            $table->json('clinical_data')->nullable();
            $table->text('internal_note')->nullable();
            $table->foreignUuid('signed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('signed_at')->nullable();
            $table->string('doctor_license_snapshot', 100)->nullable();
            $table->timestamps();
            $table->index(['status', 'signed_at']);
        });

        Schema::create('medical_record_amendments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('medical_record_id')->constrained()->restrictOnDelete();
            $table->text('reason');
            $table->json('previous_content');
            $table->json('new_content');
            $table->foreignUuid('amended_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('amended_at')->useCurrent();
            $table->index(['medical_record_id', 'amended_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE visits ADD CONSTRAINT visits_status_check CHECK (status IN ('OPEN','COMPLETED','CANCELLED'))");
            DB::statement("ALTER TABLE visits ADD CONSTRAINT visits_consent_check CHECK (consent_method IS NULL OR consent_method IN ('IN_PERSON','PHONE'))");
            DB::statement("ALTER TABLE medical_records ADD CONSTRAINT medical_records_status_check CHECK (status IN ('DRAFT','SIGNED','AMENDED','VOIDED'))");
            DB::statement("CREATE FUNCTION protect_signed_medical_record() RETURNS trigger AS $$ BEGIN IF OLD.status IN ('SIGNED','AMENDED') AND ((NEW.status IS DISTINCT FROM OLD.status AND NOT (OLD.status = 'SIGNED' AND NEW.status = 'AMENDED')) OR NEW.visit_id IS DISTINCT FROM OLD.visit_id OR NEW.chief_complaint IS DISTINCT FROM OLD.chief_complaint OR NEW.history IS DISTINCT FROM OLD.history OR NEW.physical_exam IS DISTINCT FROM OLD.physical_exam OR NEW.diagnosis IS DISTINCT FROM OLD.diagnosis OR NEW.treatment_plan IS DISTINCT FROM OLD.treatment_plan OR NEW.follow_up_date IS DISTINCT FROM OLD.follow_up_date OR NEW.clinical_data IS DISTINCT FROM OLD.clinical_data OR NEW.internal_note IS DISTINCT FROM OLD.internal_note OR NEW.signed_by IS DISTINCT FROM OLD.signed_by OR NEW.signed_at IS DISTINCT FROM OLD.signed_at OR NEW.doctor_license_snapshot IS DISTINCT FROM OLD.doctor_license_snapshot) THEN RAISE EXCEPTION 'signed medical records are immutable'; END IF; RETURN NEW; END; $$ LANGUAGE plpgsql");
            DB::statement('CREATE TRIGGER medical_records_immutable BEFORE UPDATE ON medical_records FOR EACH ROW EXECUTE FUNCTION protect_signed_medical_record()');
            DB::statement("CREATE FUNCTION prevent_medical_record_amendment_mutation() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'medical record amendments are append-only'; END; $$ LANGUAGE plpgsql");
            DB::statement('CREATE TRIGGER medical_record_amendments_immutable BEFORE UPDATE OR DELETE ON medical_record_amendments FOR EACH ROW EXECUTE FUNCTION prevent_medical_record_amendment_mutation()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS medical_record_amendments_immutable ON medical_record_amendments');
            DB::statement('DROP FUNCTION IF EXISTS prevent_medical_record_amendment_mutation()');
            DB::statement('DROP TRIGGER IF EXISTS medical_records_immutable ON medical_records');
            DB::statement('DROP FUNCTION IF EXISTS protect_signed_medical_record()');
        }

        Schema::dropIfExists('medical_record_amendments');
        Schema::dropIfExists('medical_records');
        Schema::dropIfExists('visits');
    }
};
