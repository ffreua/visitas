<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_diagnoses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_id')->constrained('admissions')->cascadeOnDelete();
            $table->enum('phase', ['SUSPECTED', 'FINAL'])->index();
            $table->string('cid_code')->index();
            $table->foreign('cid_code')->references('code')->on('cid10')->restrictOnDelete();
            $table->string('description_snapshot');
            $table->boolean('is_primary')->default(false);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_diagnoses');
    }
};
