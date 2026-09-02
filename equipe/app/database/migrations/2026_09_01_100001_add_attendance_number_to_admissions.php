<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Número de atendimento (número da internação/episódio no sistema do
 * hospital). Diferente do prontuário: o prontuário identifica a PESSOA e
 * nunca muda; o atendimento identifica a PASSAGEM e muda de uma internação
 * para outra. Ambos precisam servir de porta de entrada no cadastro.
 *
 * Nullable porque todos os episódios já cadastrados foram criados antes
 * deste campo existir — nenhum registro existente é invalidado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            $table->string('attendance_number', 50)->nullable();
        });

        Schema::table('admissions', function (Blueprint $table) {
            $table->index('attendance_number');
        });

        // Unicidade parcial: dois episódios vivos não podem compartilhar o
        // mesmo número de atendimento (seria erro de digitação), mas NULL
        // repete à vontade (episódios legados) e episódios excluídos saem
        // do índice para não travar o recadastro de um número liberado.
        DB::statement(
            'CREATE UNIQUE INDEX admissions_attendance_number_unique
             ON admissions (attendance_number)
             WHERE attendance_number IS NOT NULL AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS admissions_attendance_number_unique');

        Schema::table('admissions', function (Blueprint $table) {
            $table->dropIndex(['attendance_number']);
            $table->dropColumn('attendance_number');
        });
    }
};
