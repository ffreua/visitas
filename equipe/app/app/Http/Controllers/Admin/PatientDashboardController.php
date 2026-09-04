<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\Patient;
use App\Services\Percentiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Dashboard de gestão indexado pelo PRONTUÁRIO: o prontuário é a chave
 * estável do paciente, então é por ele que se lê a trajetória completa —
 * quantos atendimentos aquela pessoa já teve e o detalhe de cada um.
 *
 * O dashboard geral (DashboardController) agrega o serviço inteiro; este
 * responde "e este paciente específico, como foi?".
 */
class PatientDashboardController extends Controller
{
    private const EPISODE_EAGER = [
        'healthPlan', 'requestingSpecialty', 'diagnoses',
        'pendingItems', 'dailyRounds.assignedPhysician', 'dailyRounds.completer',
        'creator', 'updater', 'deleter',
    ];

    /**
     * Busca de pacientes por prontuário, nome ou número de atendimento —
     * a porta de entrada do dashboard por prontuário.
     */
    public function index(Request $request)
    {
        Gate::authorize('view-dashboards');

        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'include_deleted' => ['nullable', 'boolean'],
        ]);

        $search = trim($data['search'] ?? '');
        $includeDeleted = (bool) ($data['include_deleted'] ?? false);

        $query = Patient::query();

        if ($search !== '') {
            $upper = strtoupper($search);

            $query->where(function ($q) use ($search, $upper) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('medical_record_number', 'like', "%{$upper}%")
                    ->orWhereHas('admissions', function ($sub) use ($upper) {
                        $sub->withTrashed()->where('attendance_number', 'like', "%{$upper}%");
                    });
            });
        }

        $patients = $query->orderBy('full_name')->limit(50)->get();

        $rows = $patients->map(function (Patient $patient) use ($includeDeleted) {
            $episodes = $this->episodesQuery($patient, $includeDeleted)->get();

            return [
                'id' => $patient->id,
                'medical_record_number' => $patient->medical_record_number,
                'medical_record_confirmed' => $patient->hasConfirmedMedicalRecord(),
                'full_name' => $patient->full_name,
                'date_of_birth' => optional($patient->date_of_birth)->toDateString(),
                'episodes_total' => $episodes->count(),
                'episodes_active' => $episodes->where('status', 'ACTIVE')->count(),
                'last_admission_at' => optional($episodes->max('admission_at'))->toIso8601String(),
                'attendance_numbers' => $episodes->pluck('attendance_number')->filter()->values(),
            ];
        });

        return response()->json([
            'search' => $search,
            'count' => $rows->count(),
            'patients' => $rows->values(),
        ]);
    }

    /**
     * Dashboard de um prontuário: consolidado + detalhe atendimento a
     * atendimento.
     */
    public function show(Request $request, Patient $patient)
    {
        Gate::authorize('view-dashboards');

        $data = $request->validate(['include_deleted' => ['nullable', 'boolean']]);
        $includeDeleted = (bool) ($data['include_deleted'] ?? false);

        $episodes = $this->episodesQuery($patient, $includeDeleted)
            ->with(self::EPISODE_EAGER)
            ->orderByDesc('admission_at')
            ->get();

        return response()->json([
            'patient' => [
                ...$patient->toArray(),
                'medical_record_confirmed' => $patient->hasConfirmedMedicalRecord(),
            ],
            'include_deleted' => $includeDeleted,
            'summary' => $this->summary($episodes),
            'episodes' => $episodes->map(fn (Admission $a) => $this->episode($a))->values(),
        ]);
    }

    private function episodesQuery(Patient $patient, bool $includeDeleted)
    {
        $query = $patient->admissions();

        if ($includeDeleted) {
            $query->withTrashed();
        }

        return $query;
    }

    private function summary($episodes): array
    {
        $followupDays = $episodes->map(fn (Admission $a) => $this->followupDays($a))->all();
        $hospitalDays = $episodes->filter(fn (Admission $a) => $a->hospital_discharge_at)
            ->map(fn (Admission $a) => round($a->admission_at->diffInHours($a->hospital_discharge_at, true) / 24, 1))
            ->all();

        $rounds = $episodes->flatMap->dailyRounds;
        $completedRounds = $rounds->filter(fn ($r) => $r->completed_at);
        $pending = $episodes->flatMap->pendingItems;

        $plans = $episodes->where('payer_type', 'HEALTH_PLAN')
            ->groupBy(fn (Admission $a) => $a->health_plan_name_snapshot ?? $a->healthPlan?->name ?? 'Não informado')
            ->map(fn ($group, $name) => ['plan' => $name, 'episodes' => $group->count()])
            ->values();

        return [
            'episodes_total' => $episodes->count(),
            'episodes_active' => $episodes->where('status', 'ACTIVE')->count(),
            'episodes_closed' => $episodes->where('status', 'CLOSED')->count(),
            'episodes_deleted' => $episodes->filter(fn (Admission $a) => $a->deleted_at !== null)->count(),
            'first_admission_at' => optional($episodes->min('admission_at'))->toIso8601String(),
            'last_admission_at' => optional($episodes->max('admission_at'))->toIso8601String(),
            'interconsults' => $episodes->where('care_type', 'INTERCONSULT')->count(),
            'single_evaluations' => $episodes->where('followup_mode', 'SINGLE_EVALUATION')->count(),
            'payers' => [
                'PRIVATE' => $episodes->where('payer_type', 'PRIVATE')->count(),
                'HEALTH_PLAN' => $episodes->where('payer_type', 'HEALTH_PLAN')->count(),
            ],
            'plans' => $plans,
            'neurology_days_total' => round(array_sum($followupDays), 1),
            'neurology_days' => Percentiles::summarize($followupDays),
            'hospital_days' => Percentiles::summarize($hospitalDays),
            'hospital_days_sample_size' => count($hospitalDays),
            'rounds_total' => $rounds->count(),
            'rounds_completed' => $completedRounds->count(),
            'rounds_coverage_pct' => $rounds->count() > 0
                ? round($completedRounds->count() / $rounds->count() * 100, 1)
                : null,
            'physicians' => $completedRounds->groupBy('completed_by')
                ->map(fn ($group, $id) => [
                    'physician' => $group->first()->completer?->full_name ?? 'Usuário #'.$id,
                    'rounds' => $group->count(),
                ])->sortByDesc('rounds')->values(),
            'pending_total' => $pending->count(),
            'pending_open' => $pending->where('status', 'OPEN')->count(),
            'readmission_gaps_days' => $this->readmissionGaps($episodes),
        ];
    }

    /**
     * Intervalo entre o encerramento de um episódio e a entrada do
     * seguinte — é o dado que sustenta a leitura de reinternação DESTE
     * prontuário (o dashboard geral só mostra os totais do serviço).
     *
     * @return array<int, array{from_episode_id: int, to_episode_id: int, gap_days: float}>
     */
    private function readmissionGaps($episodes): array
    {
        $chronological = $episodes->sortBy('admission_at')->values();
        $gaps = [];

        for ($i = 1; $i < $chronological->count(); $i++) {
            $previousClosedAt = $chronological[$i - 1]->neurology_followup_closed_at;

            if ($previousClosedAt === null) {
                continue;
            }

            $gaps[] = [
                'from_episode_id' => $chronological[$i - 1]->id,
                'to_episode_id' => $chronological[$i]->id,
                'gap_days' => round($previousClosedAt->diffInHours($chronological[$i]->admission_at, true) / 24, 1),
            ];
        }

        return $gaps;
    }

    private function episode(Admission $admission): array
    {
        $rounds = $admission->dailyRounds;
        $completed = $rounds->filter(fn ($r) => $r->completed_at);
        $mapDiagnoses = fn ($collection) => $collection->map(fn ($d) => [
            'cid_code' => $d->cid_code,
            'description' => $d->description_snapshot,
            'is_primary' => (bool) $d->is_primary,
        ])->values();

        return [
            'id' => $admission->id,
            'attendance_number' => $admission->attendance_number,
            'status' => $admission->status,
            'care_type' => $admission->care_type,
            'followup_mode' => $admission->followup_mode,
            'admission_at' => optional($admission->admission_at)->toIso8601String(),
            'hospital_discharge_at' => optional($admission->hospital_discharge_at)->toIso8601String(),
            'neurology_followup_started_at' => optional($admission->neurology_followup_started_at)->toIso8601String(),
            'neurology_followup_closed_at' => optional($admission->neurology_followup_closed_at)->toIso8601String(),
            'neurology_days' => $this->followupDays($admission),
            'hospital_days' => $admission->hospital_discharge_at
                ? round($admission->admission_at->diffInHours($admission->hospital_discharge_at, true) / 24, 1)
                : null,
            'payer_type' => $admission->payer_type,
            'health_plan' => $admission->payer_type === 'PRIVATE'
                ? null
                : ($admission->health_plan_name_snapshot ?? $admission->healthPlan?->name),
            'requesting_specialty' => $admission->requestingSpecialty?->name,
            'consult_requested_at' => optional($admission->consult_requested_at)->toIso8601String(),
            'first_neurology_evaluation_at' => optional($admission->first_neurology_evaluation_at)->toIso8601String(),
            'origin' => $admission->originLabel(),
            'unit' => $admission->unit,
            'bed' => $admission->bed,
            'brief_history' => $admission->brief_history,
            'discharge_outcome' => $admission->discharge_outcome,
            'followup_plan_documented' => $admission->followup_plan_documented,
            'diagnoses' => [
                'suspected' => $mapDiagnoses($admission->diagnoses->where('phase', 'SUSPECTED')),
                'final' => $mapDiagnoses($admission->diagnoses->where('phase', 'FINAL')),
            ],
            'rounds' => [
                'total' => $rounds->count(),
                'completed' => $completed->count(),
                'coverage_pct' => $rounds->count() > 0 ? round($completed->count() / $rounds->count() * 100, 1) : null,
                'physicians' => $rounds->map(fn ($r) => $r->completer?->full_name ?? $r->assignedPhysician?->full_name)
                    ->filter()->unique()->values(),
            ],
            'pending_items' => [
                'total' => $admission->pendingItems->count(),
                'open' => $admission->pendingItems->where('status', 'OPEN')->count(),
                'items' => $admission->pendingItems->map(fn ($p) => [
                    'description' => $p->description,
                    'status' => $p->status,
                    'created_at' => optional($p->created_at)->toIso8601String(),
                    'resolved_at' => optional($p->resolved_at)->toIso8601String(),
                ])->values(),
            ],
            'created_by' => $admission->creator?->full_name,
            'updated_by' => $admission->updater?->full_name,
            'deleted_at' => optional($admission->deleted_at)->toIso8601String(),
            'deleted_by' => $admission->deleter?->full_name,
            'deletion_reason' => $admission->deletion_reason,
            'version' => $admission->version,
        ];
    }

    private function followupDays(Admission $admission): float
    {
        $end = $admission->neurology_followup_closed_at ?? now();

        return round(max(0, $admission->neurology_followup_started_at->diffInHours($end, true) / 24), 1);
    }
}
