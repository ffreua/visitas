<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `first_neurology_evaluation_at` passou a ser gravado na primeira visita
 * assinada do episódio (DailyRoundController::complete). Antes disso ele só
 * era preenchido no encerramento de avaliação única — na prática, ficava nulo
 * em quase todo episódio, e o indicador "tempo solicitação → 1ª avaliação"
 * nunca teve amostra.
 *
 * Este backfill recupera o dado que já existe no banco: para cada episódio sem
 * o campo, usa o `completed_at` da visita mais antiga já assinada. Onde não há
 * visita assinada, permanece nulo — inventar uma data seria pior que a
 * ausência, porque o indicador passaria a mentir com aparência de dado.
 *
 * Só preenche coluna vazia; nenhum valor existente é sobrescrito.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE admissions
               SET first_neurology_evaluation_at = (
                     SELECT MIN(dr.completed_at)
                       FROM daily_rounds dr
                      WHERE dr.admission_id = admissions.id
                        AND dr.completed_at IS NOT NULL
                   )
             WHERE first_neurology_evaluation_at IS NULL
               AND EXISTS (
                     SELECT 1
                       FROM daily_rounds dr
                      WHERE dr.admission_id = admissions.id
                        AND dr.completed_at IS NOT NULL
                   )
        SQL);
    }

    /**
     * Irreversível por escolha: o valor anterior era "nulo por falta de
     * registro", e reverter apagaria também as primeiras avaliações gravadas
     * corretamente depois desta versão. Não há o que restaurar.
     */
    public function down(): void
    {
        //
    }
};
