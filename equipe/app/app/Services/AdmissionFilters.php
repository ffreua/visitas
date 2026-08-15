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
    public static function apply(Builder $query, array $filters, bool $includeDeleted = false): Builder
    {
        if ($includeDeleted) {
            $query->withTrashed();
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('admission_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('admission_at', '<=', $filters['date_to']);
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
