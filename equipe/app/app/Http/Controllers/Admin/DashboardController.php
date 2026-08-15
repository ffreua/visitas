<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DashboardFilterRequest;
use App\Models\Admission;
use App\Models\User;
use App\Services\AdmissionFilters;
use App\Services\Percentiles;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    /**
     * Indicadores administrativos (seções 55, 67-80 do PRD). Todos os
     * números excluem registros com deleted_at != null por padrão — só
     * entram se include_deleted=1 for passado explicitamente (seção 78).
     */
    public function index(DashboardFilterRequest $request)
    {
        Gate::authorize('viewAny', User::class);

        $filters = $request->validated();
        $includeDeleted = (bool) ($filters['include_deleted'] ?? false);

        $admissions = AdmissionFilters::query($filters, $includeDeleted)->get();

        return response()->json([
            'filters_applied' => $filters,
            'volume' => $this->volume($admissions),
            'payers' => $this->payers($admissions),
            'interconsults' => $this->interconsults($admissions),
            'length_of_stay' => $this->lengthOfStay($admissions),
            'visit_coverage' => $this->visitCoverage($admissions),
            'diagnoses' => $this->diagnoses($admissions),
            'diagnostic_agreement' => $this->diagnosticAgreement($admissions),
            'readmissions' => $this->readmissions($admissions, $includeDeleted),
            'pending_items' => $this->pendingItems($admissions),
            'single_evaluations' => $this->singleEvaluations($admissions),
            'physicians' => $this->physicians($admissions),
        ]);
    }

    public function dataQuality()
    {
        Gate::authorize('viewAny', User::class);

        $active = Admission::active()->with(['diagnoses', 'requestingSpecialty', 'dailyRounds', 'pendingItems'])->get();
        $today = now()->toDateString();

        $noHypothesis = $active->filter(fn ($a) => $a->diagnoses->where('phase', 'SUSPECTED')->isEmpty());
        $interconsultsNoSpecialty = $active->where('care_type', 'INTERCONSULT')->filter(fn ($a) => ! $a->requesting_specialty_id);
        $interconsultsNoRequestTime = $active->where('care_type', 'INTERCONSULT')->filter(fn ($a) => ! $a->consult_requested_at);
        $noResponsibleToday = $active->filter(fn ($a) => ! $a->dailyRounds->contains(fn ($r) => $r->round_date->toDateString() === $today && $r->assigned_physician_id));
        $notVisitedToday = $active->filter(fn ($a) => ! $a->dailyRounds->contains(fn ($r) => $r->round_date->toDateString() === $today && $r->completed_at));
        $closedNoFinalDx = Admission::closed()->with('diagnoses')->get()->filter(fn ($a) => $a->diagnoses->where('phase', 'FINAL')->isEmpty());
        $noPayerDefined = $active->filter(fn ($a) => ! $a->payer_type);
        $longAdmissions = $active->filter(fn ($a) => $a->admission_at->diffInDays(now()) > 30);
        $oldOpenSingleEval = $active->where('followup_mode', 'SINGLE_EVALUATION')->filter(fn ($a) => $a->admission_at->diffInDays(now()) > 3);
        $oldPendingItems = $active->flatMap->pendingItems->where('status', 'OPEN')->filter(fn ($p) => $p->created_at->diffInDays(now()) > 14);

        return response()->json([
            'active_without_suspected_diagnosis' => $noHypothesis->count(),
            'interconsults_without_specialty' => $interconsultsNoSpecialty->count(),
            'interconsults_without_request_time' => $interconsultsNoRequestTime->count(),
            'without_responsible_today' => $noResponsibleToday->count(),
            'not_visited_today' => $notVisitedToday->count(),
            'discharges_without_final_diagnosis' => $closedNoFinalDx->count(),
            'without_payer_defined' => $noPayerDefined->count(),
            'admissions_over_30_days' => $longAdmissions->count(),
            'single_evaluations_open_over_3_days' => $oldOpenSingleEval->count(),
            'pending_items_open_over_14_days' => $oldPendingItems->count(),
        ]);
    }

    private function volume($admissions): array
    {
        return [
            'unique_patients' => $admissions->pluck('patient_id')->unique()->count(),
            'episodes' => $admissions->count(),
            'neurology_patient_days' => round($admissions->sum(fn ($a) => $this->followupDays($a)), 1),
            'new_interconsults' => $admissions->where('care_type', 'INTERCONSULT')->count(),
            'single_evaluations' => $admissions->where('followup_mode', 'SINGLE_EVALUATION')->count(),
            'discharges' => $admissions->where('status', 'CLOSED')->count(),
            'currently_active' => $admissions->where('status', 'ACTIVE')->count(),
        ];
    }

    private function followupDays($admission): float
    {
        $end = $admission->neurology_followup_closed_at ?? now();

        return max(0, $admission->neurology_followup_started_at->diffInDays($end, true));
    }

    private function payers($admissions): array
    {
        $byPayerType = [
            'PRIVATE' => $admissions->where('payer_type', 'PRIVATE')->count(),
            'HEALTH_PLAN' => $admissions->where('payer_type', 'HEALTH_PLAN')->count(),
        ];

        $byPlan = $admissions->where('payer_type', 'HEALTH_PLAN')->groupBy(fn ($a) => $a->health_plan_name_snapshot ?? $a->healthPlan?->name ?? 'Não informado')
            ->map(function ($group, $planName) {
                return [
                    'plan' => $planName,
                    'episodes' => $group->count(),
                    'patient_days' => round($group->sum(fn ($a) => $this->followupDays($a)), 1),
                    'median_followup_days' => Percentiles::summarize($group->map(fn ($a) => $this->followupDays($a))->all())['median'],
                ];
            })->values();

        return ['private_vs_plan' => $byPayerType, 'by_plan' => $byPlan];
    }

    private function interconsults($admissions): array
    {
        $interconsults = $admissions->where('care_type', 'INTERCONSULT');

        $bySpecialty = $interconsults->groupBy(fn ($a) => $a->requestingSpecialty?->name ?? 'Não informado')
            ->map(fn ($group, $name) => ['specialty' => $name, 'count' => $group->count()])->values();

        $responseTimes = $interconsults
            ->filter(fn ($a) => $a->consult_requested_at && $a->first_neurology_evaluation_at)
            ->map(fn ($a) => $a->consult_requested_at->diffInHours($a->first_neurology_evaluation_at, true) / 24)
            ->all();

        return [
            'count' => $interconsults->count(),
            'by_specialty' => $bySpecialty,
            'response_time_days' => Percentiles::summarize($responseTimes),
        ];
    }

    private function lengthOfStay($admissions): array
    {
        $hospitalLos = $admissions->filter(fn ($a) => $a->hospital_discharge_at)
            ->map(fn ($a) => $a->admission_at->diffInHours($a->hospital_discharge_at, true) / 24)->all();

        $neurologyLos = $admissions->map(fn ($a) => $this->followupDays($a))->all();

        return [
            'hospital_los_days' => Percentiles::summarize($hospitalLos),
            'hospital_los_sample_size' => count($hospitalLos),
            'neurology_followup_days' => Percentiles::summarize($neurologyLos),
        ];
    }

    private function visitCoverage($admissions): array
    {
        $active = $admissions->where('status', 'ACTIVE');
        $today = now()->toDateString();

        $unassignedToday = $active->filter(fn ($a) => ! $a->dailyRounds->contains(
            fn ($r) => $r->round_date->toDateString() === $today && $r->assigned_physician_id
        ))->count();

        $notVisitedToday = $active->filter(fn ($a) => ! $a->dailyRounds->contains(
            fn ($r) => $r->round_date->toDateString() === $today && $r->completed_at
        ))->count();

        $activePatientDays = 0;
        $visitedPatientDays = 0;
        foreach ($active as $admission) {
            $activePatientDays += count($admission->dailyRounds);
            $visitedPatientDays += $admission->dailyRounds->filter(fn ($r) => $r->completed_at)->count();
        }

        return [
            'active_patient_days' => $activePatientDays,
            'visited_patient_days' => $visitedPatientDays,
            'coverage_pct' => $activePatientDays > 0 ? round($visitedPatientDays / $activePatientDays * 100, 1) : null,
            'unassigned_today' => $unassignedToday,
            'not_visited_today' => $notVisitedToday,
        ];
    }

    private function diagnoses($admissions): array
    {
        $suspected = $admissions->flatMap(fn ($a) => $a->diagnoses->where('phase', 'SUSPECTED')->where('is_primary', true));
        $final = $admissions->flatMap(fn ($a) => $a->diagnoses->where('phase', 'FINAL')->where('is_primary', true));

        $top = fn ($collection) => $collection->groupBy('cid_code')
            ->map(fn ($group, $code) => ['cid_code' => $code, 'description' => $group->first()->description_snapshot, 'count' => $group->count()])
            ->sortByDesc('count')->take(10)->values();

        return [
            'top_suspected' => $top($suspected),
            'top_final' => $top($final),
        ];
    }

    private function diagnosticAgreement($admissions): array
    {
        $closedWithBoth = $admissions->where('status', 'CLOSED')->filter(function ($a) {
            return $a->diagnoses->where('phase', 'SUSPECTED')->where('is_primary', true)->isNotEmpty()
                && $a->diagnoses->where('phase', 'FINAL')->where('is_primary', true)->isNotEmpty();
        });

        $concordant = 0;
        $changed = 0;
        foreach ($closedWithBoth as $a) {
            $suspectedCode = $a->diagnoses->where('phase', 'SUSPECTED')->where('is_primary', true)->first()->cid_code;
            $finalCode = $a->diagnoses->where('phase', 'FINAL')->where('is_primary', true)->first()->cid_code;
            $suspectedCode === $finalCode ? $concordant++ : $changed++;
        }

        $undetermined = $admissions->where('status', 'CLOSED')->count() - $closedWithBoth->count();

        return ['concordant' => $concordant, 'changed' => $changed, 'undetermined' => $undetermined];
    }

    private function readmissions($admissions, bool $includeDeleted): array
    {
        $patientIds = $admissions->pluck('patient_id')->unique();
        $query = Admission::whereIn('patient_id', $patientIds)->orderBy('patient_id')->orderBy('admission_at');
        if ($includeDeleted) {
            $query->withTrashed();
        }
        $allByPatient = $query->get()->groupBy('patient_id');

        $within7 = 0;
        $within30 = 0;
        foreach ($allByPatient as $episodes) {
            $episodes = $episodes->values();
            for ($i = 1; $i < $episodes->count(); $i++) {
                $previousClosedAt = $episodes[$i - 1]->neurology_followup_closed_at;
                if ($previousClosedAt === null) {
                    continue;
                }
                $gapDays = $previousClosedAt->diffInDays($episodes[$i]->admission_at, true);
                if ($gapDays <= 7) {
                    $within7++;
                }
                if ($gapDays <= 30) {
                    $within30++;
                }
            }
        }

        return [
            'within_7_days' => $within7,
            'within_30_days' => $within30,
            'note' => 'Readmissão detectada entre episódios existentes nesta aplicação.',
        ];
    }

    private function pendingItems($admissions): array
    {
        $pending = $admissions->flatMap->pendingItems;
        $resolved = $pending->where('status', 'DONE');

        $resolutionDays = $resolved->filter(fn ($p) => $p->resolved_at)
            ->map(fn ($p) => $p->created_at->diffInHours($p->resolved_at, true) / 24)->all();

        $openAtClosure = $admissions->where('status', 'CLOSED')
            ->sum(fn ($a) => $a->pendingItems->where('status', 'OPEN')->count());

        return [
            'open' => $pending->where('status', 'OPEN')->count(),
            'created' => $pending->count(),
            'resolved' => $resolved->count(),
            'median_resolution_days' => Percentiles::summarize($resolutionDays)['median'],
            'open_at_closure' => $openAtClosure,
        ];
    }

    private function singleEvaluations($admissions): array
    {
        $single = $admissions->where('followup_mode', 'SINGLE_EVALUATION');

        $bySpecialty = $single->groupBy(fn ($a) => $a->requestingSpecialty?->name ?? 'N/A')
            ->map(fn ($g, $name) => ['specialty' => $name, 'count' => $g->count()])->values();

        $byPlan = $single->groupBy(fn ($a) => $a->payer_type === 'PRIVATE' ? 'Particular' : ($a->health_plan_name_snapshot ?? 'Não informado'))
            ->map(fn ($g, $name) => ['plan' => $name, 'count' => $g->count()])->values();

        $responseTimes = $single->filter(fn ($a) => $a->consult_requested_at && $a->first_neurology_evaluation_at)
            ->map(fn ($a) => $a->consult_requested_at->diffInHours($a->first_neurology_evaluation_at, true) / 24)->all();

        $completedSameDay = $single->filter(function ($a) {
            return $a->consult_requested_at && $a->first_neurology_evaluation_at
                && $a->consult_requested_at->toDateString() === $a->first_neurology_evaluation_at->toDateString();
        })->count();

        $completed = $single->filter(fn ($a) => $a->first_neurology_evaluation_at)->count();

        return [
            'count' => $single->count(),
            'by_specialty' => $bySpecialty,
            'by_plan' => $byPlan,
            'response_time_days' => Percentiles::summarize($responseTimes),
            'same_day_pct' => $completed > 0 ? round($completedSameDay / $completed * 100, 1) : null,
            'converted_to_followup_note' => 'Conversões viram followup_mode=ONGOING e saem desta contagem — auditoria em audit_logs (UPDATE_ADMISSION).',
        ];
    }

    private function physicians($admissions): array
    {
        $rounds = $admissions->flatMap->dailyRounds;

        $byPhysician = $rounds->filter(fn ($r) => $r->completed_by)
            ->groupBy('completed_by')
            ->map(function ($group, $physicianId) use ($admissions) {
                $physician = $group->first()->completer;
                $admissionIds = $group->pluck('admission_id')->unique();
                $firstEvaluations = $admissions->whereIn('id', $admissionIds)
                    ->filter(fn ($a) => $a->first_neurology_evaluation_at)->count();
                $singleEvals = $admissions->whereIn('id', $admissionIds)
                    ->where('followup_mode', 'SINGLE_EVALUATION')->count();

                return [
                    'physician' => $physician?->full_name ?? "Usuário #{$physicianId}",
                    'rounds' => $group->count(),
                    'unique_patients' => $admissionIds->count(),
                    'first_evaluations' => $firstEvaluations,
                    'single_evaluations' => $singleEvals,
                ];
            })->values();

        return ['by_physician' => $byPhysician];
    }
}
