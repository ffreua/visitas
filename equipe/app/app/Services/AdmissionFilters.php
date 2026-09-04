<?php

namespace App\Services;

use App\Models\Admission;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filtros compartilhados entre o dashboard de indicadores e a exportação —
 * garante que os dois nunca divirjam silenciosamente sobre o que "período X,
 * médico Y, CID Z" significa.
 */
class AdmissionFilters
{
    /**
     * "Entrou no período": recorta por data de ENTRADA. Responde "quantos
     * casos novos chegaram".
     */
    public const PERIOD_ADMISSION = 'ADMISSION';

    /**
     * "Esteve sob acompanhamento no período": recorta por SOBREPOSIÇÃO entre
     * o acompanhamento e a janela. Responde "quanto trabalho o serviço teve".
     *
     * A diferença não é cosmética: pelo recorte por entrada, o paciente que
     * entrou em julho e seguiu internado agosto inteiro simplesmente não
     * aparece no relatório de agosto — nem ele, nem os patient-days dele, nem
     * as visitas que a equipe fez nele. Num serviço de Neurologia hospitalar,
     * que é onde mora a longa permanência, isso subestima a carga de forma
     * sistemática.
     */
    public const PERIOD_OVERLAP = 'OVERLAP';

    /**
     * O padrão é PERIOD_ADMISSION para a EXPORTAÇÃO não mudar de significado
     * (séries já extraídas continuam comparáveis). O dashboard pede
     * PERIOD_OVERLAP explicitamente — é uma divergência deliberada e nomeada,
     * não um descuido.
     */
    public static function apply(Builder $query, array $filters, bool $includeDeleted = false): Builder
    {
        if ($includeDeleted) {
            $query->withTrashed();
        }

        $mode = $filters['period_mode'] ?? self::PERIOD_ADMISSION;

        if ($mode === self::PERIOD_OVERLAP) {
            // Sobreposição: começou até o fim da janela E (segue aberto OU
            // encerrou depois do início da janela).
            if (! empty($filters['date_to'])) {
                $query->whereDate('neurology_followup_started_at', '<=', $filters['date_to']);
            }
            if (! empty($filters['date_from'])) {
                $query->where(function ($q) use ($filters) {
                    $q->whereNull('neurology_followup_closed_at')
                        ->orWhereDate('neurology_followup_closed_at', '>=', $filters['date_from']);
                });
            }
        } else {
            if (! empty($filters['date_from'])) {
                $query->whereDate('admission_at', '>=', $filters['date_from']);
            }
            if (! empty($filters['date_to'])) {
                $query->whereDate('admission_at', '<=', $filters['date_to']);
            }
        }
        foreach (['care_type', 'followup_mode', 'payer_type', 'health_plan_id', 'requesting_specialty_id', 'status'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['physician_id'])) {
            $query->whereHas('dailyRounds', fn ($q) => $q->where('assigned_physician_id', $filters['physician_id'])
                ->orWhere('completed_by', $filters['physician_id']));
        }
        if (! empty($filters['cid_code'])) {
            $query->whereHas('diagnoses', fn ($q) => $q->where('cid_code', $filters['cid_code']));
        }

        return $query;
    }

    public static function query(array $filters, bool $includeDeleted = false): Builder
    {
        $query = Admission::query()->with([
            'patient', 'healthPlan', 'requestingSpecialty', 'diagnoses',
            'pendingItems', 'dailyRounds.assignedPhysician', 'dailyRounds.completer',
        ]);

        return self::apply($query, $filters, $includeDeleted);
    }
}
