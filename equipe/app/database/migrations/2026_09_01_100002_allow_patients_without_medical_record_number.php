<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O prontuário deixa de ser obrigatório NO MOMENTO DO CADASTRO: o paciente
 * pode entrar pelo número de atendimento e receber o prontuário depois.
 * Continua único (o índice único do SQLite aceita múltiplos NULL) e, uma vez
 * confirmado (medical_record_confirmed_at != null), passa a ser imutável —
 * regra reforçada em Patient::booted() e em PatientController::update.
 *
 * Todo paciente já cadastrado tem prontuário e é tratado como confirmado:
 * o backfill evita que registros antigos virassem "editáveis" por omissão.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->timestamp('medical_record_confirmed_at')->nullable();
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->string('medical_record_number')->nullable()->change();
        });

        DB::table('patients')
            ->whereNotNull('medical_record_number')
            ->whereNull('medical_record_confirmed_at')
            ->update(['medical_record_confirmed_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)')]);
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('medical_record_number')->nullable(false)->change();
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('medical_record_confirmed_at');
        });
    }
};
