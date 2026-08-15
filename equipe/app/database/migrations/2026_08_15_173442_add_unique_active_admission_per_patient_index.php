<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reforça no banco a regra "um paciente só pode ter um episódio ACTIVE por
 * vez" (seção 27 do PRD) — a checagem em PHP (AdmissionController::store)
 * sozinha tem uma janela de corrida entre o SELECT e o INSERT, e não é
 * revalidada ao restaurar um episódio excluído. Índice único parcial é a
 * garantia de última linha contra episódio ativo duplicado.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX admissions_one_active_per_patient
             ON admissions (patient_id)
             WHERE status = \'ACTIVE\' AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS admissions_one_active_per_patient');
    }
};
