<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data de nascimento deixa de ser obrigatória NO CADASTRO: na porta da
 * enfermaria muitas vezes só se tem o nome e o número de atendimento, e
 * exigir a data ali levava a inventar valor — pior que deixar em branco.
 *
 * A cobrança passa para o encerramento do acompanhamento, junto com o
 * prontuário (ver AdmissionController::close): é lá que o episódio vira
 * dado de gestão e precisa estar completo.
 *
 * Nenhum paciente existente é afetado — todos têm data preenchida, já que
 * até aqui o campo era obrigatório.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable(false)->change();
        });
    }
};
