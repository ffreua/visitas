<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('patient_id')->constrained('patients')->restrictOnDelete();

            $table->dateTime('admission_at');
            $table->dateTime('hospital_discharge_at')->nullable();

            $table->dateTime('neurology_followup_started_at');
            $table->dateTime('neurology_followup_closed_at')->nullable();

            $table->enum('status', ['ACTIVE', 'CLOSED'])->default('ACTIVE');
            $table->enum('care_type', ['INSTITUTIONAL', 'INTERCONSULT']);
            $table->enum('followup_mode', ['ONGOING', 'SINGLE_EVALUATION']);

            $table->enum('payer_type', ['HEALTH_PLAN', 'PRIVATE']);
            $table->foreignId('health_plan_id')->nullable()->constrained('health_plans')->restrictOnDelete();
            $table->string('health_plan_name_snapshot')->nullable();

            $table->string('origin')->nullable();
            $table->string('unit')->nullable();
            $table->string('bed')->nullable();

            $table->foreignId('requesting_specialty_id')->nullable()->constrained('medical_specialties')->restrictOnDelete();
            $table->text('consult_reason')->nullable();
            $table->string('consult_priority')->nullable();
            $table->dateTime('consult_requested_at')->nullable();
            $table->dateTime('first_neurology_evaluation_at')->nullable();

            $table->text('brief_history')->nullable();

            $table->text('discharge_outcome')->nullable();
            $table->text('followup_plan_documented')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedInteger('version')->default(1);

            $table->timestamp('deleted_at')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deletion_reason')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('admission_at');
            $table->index('deleted_at');
            $table->index('care_type');
            $table->index('followup_mode');
            $table->index('payer_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admissions');
    }
};
