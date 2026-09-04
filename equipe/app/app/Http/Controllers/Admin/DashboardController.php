<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DashboardFilterRequest;
use App\Models\Admission;
use App\Services\AdmissionFilters;
use App\Services\Percentiles;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    private const READMISSION_NOTE = 'Reentradas ocorridas no período, medidas a partir do encerramento anterior do mesmo paciente nesta aplicação. "Até 30 dias" inclui as de até 7.';

    /**
     * Indicadores administrativos (seções 55, 67-80 do PRD). Todos os
     * números excluem registros com deleted_at != null por padrão — só
     * entram se include_deleted=1 for passado explicitamente (seção 78).
     *
     * Duas regras valem para o painel inteiro e explicam quase todas as
     * decisões deste arquivo:
     *
     * 1. O recorte padrão é SOBREPOSIÇÃO com a janela, não data de entrada
     *    (ver AdmissionFilters::PERIOD_OVERLAP). Quem entrou em julho e
     *    seguiu internado em agosto conta no relatório de agosto.
     * 2. Evento datado é contado pela data DO EVENTO. "Altas de agosto" são
     *    os encerramentos ocorridos em agosto — não os episódios que entraram
     *    em agosto e por acaso já estão encerrados hoje.
     */
    public function index(DashboardFilterRequest $request)
    {
        Gate::authorize('view-dashboards');

        $filters = $request->validated();
        $filters['period_mode'] ??= AdmissionFilters::PERIOD_OVERLAP;
        $includeDeleted = (bool) ($filters['include_deleted'] ?? false);

        [$from, $to] = $this->window($filters);

        $admissions = AdmissionFilters::query($filters, $includeDeleted)->get();

        return response()->json([
            'filters_applied' => $filters,
            'period' => [
                'from' => $from?->toDateString(),
                'to' => $to->toDateString(),
                'mode' => $filters['period_mode'],
                'days' => $from ? (int) $from->diffInDays($to) + 1 : null,
            ],
            'volume' => $this->volume($admissions, $from, $to),
            'previous_period' => $this->previousPeriod($filters, $includeDeleted, $from, $to),
            'monthly_series' => $this->monthlySeries($admissions, $from, $to),
            'payers' => $this->payers($admissions, $from, $to),
            'origins' => $this->origins($admissions, $from, $to),
            'interconsults' => $this->interconsults($admissions),
            'length_of_stay' => $this->lengthOfStay($admissions, $from, $to),
            'visit_coverage' => $this->visitCoverage($admissions, $from, $to),
            'today' => $this->today(),
            'diagnoses' => $this->diagnoses($admissions),
            'diagnostic_agreement' => $this->diagnosticAgreement($admissions, $from, $to),
            'readmissions' => $this->readmissions($admissions, $includeDeleted, $from, $to),
            'pending_items' => $this->pendingItems($admissions, $from, $to),
            'single_evaluations' => $this->singleEvaluations($admissions),
            'physicians' => $this->physicians($admissions, $from, $to),
        ]);
    }

    // -----------------------------------------------------------------
    // Janela e recortes de tempo
    // -----------------------------------------------------------------

    /** @return array{0: ?Carbon, 1: Carbon} */
    private function window(array $filters): array
    {
        $from = ! empty($filters['date_from']) ? Carbon::parse($filters['date_from'])->startOfDay() : null;
        $to = ! empty($filters['date_to']) ? Carbon::parse($filters['date_to'])->endOfDay() : now();

        return [$from, $to];
    }

    /** O instante caiu dentro da janela? */
    private function inWindow(?Carbon $moment, ?Carbon $from, Carbon $to): bool
    {
        if ($moment === null) {
            return false;
        }

        return ($from === null || $moment->gte($from)) && $moment->lte($to);
    }

    /**
     * Trecho do acompanhamento que cai DENTRO da janela. É o que transforma
     * "esteve internado 90 dias" em "esteve sob nossos cuidados 12 dias
     * durante agosto" — sem isto, um episódio longo despejaria a duração
     * inteira dentro de qualquer mês em que aparecesse.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function clip($admission, ?Carbon $from, Carbon $to): ?array
    {
        $start = $admission->neurology_followup_started_at?->copy();
        if ($start === null) {
            return null;
        }

        $end = ($admission->neurology_followup_closed_at ?? now())->copy();
        if ($end->lt($start)) {
            $end = $start->copy();
        }

        if ($from && $start->lt($from)) {
            $start = $from->copy();
        }
        if ($end->gt($to)) {
            $end = $to->copy();
        }

        return $start->gt($end) ? null : [$start, $end];
    }

    /**
     * Dias de CALENDÁRIO sob acompanhamento dentro da janela. Contagem
     * inclusiva: entrar e sair no mesmo dia é 1 dia, não 0.
     *
     * É a MESMA unidade usada como patient-day e como denominador da
     * cobertura, de propósito. Medir patient-days em duração fracionária e a
     * cobertura em dias de calendário fazia a tela mostrar dois números
     * diferentes ("410,4 patient-days" e "456 patient-days") com o mesmo
     * nome, sem que nada explicasse a diferença.
     */
    private function clippedCalendarDays($admission, ?Carbon $from, Carbon $to): int
    {
        $clip = $this->clip($admission, $from, $to);

        if ($clip === null) {
            return 0;
        }

        return (int) $clip[0]->copy()->startOfDay()->diffInDays($clip[1]->copy()->startOfDay()) + 1;
    }

    /**
     * Dias decorridos entre dois instantes, nunca negativo.
     *
     * O cálculo antigo usava diferença ABSOLUTA, que transforma um intervalo
     * invertido em atraso positivo: a interconsulta vista antes de a
     * solicitação ser registrada no sistema (acontece) virava "2 dias de
     * espera" em vez de "atendida na hora". Zero é a leitura honesta.
     */
    private function elapsedDays(Carbon $start, Carbon $end): float
    {
        return max(0.0, $start->diffInHours($end) / 24);
    }

    /** Duração total do acompanhamento, sem recorte — para estatística de permanência. */
    private function followupDays($admission): float
    {
        $end = $admission->neurology_followup_closed_at ?? now();

        return max(0, $admission->neurology_followup_started_at->diffInDays($end, true));
    }

    // -----------------------------------------------------------------
    // Blocos
    // -----------------------------------------------------------------

    private function volume($admissions, ?Carbon $from, Carbon $to): array
    {
        return [
            'unique_patients' => $admissions->pluck('patient_id')->unique()->count(),
            'episodes' => $admissions->count(),
            'neurology_patient_days' => $admissions->sum(fn ($a) => $this->clippedCalendarDays($a, $from, $to)),
            'new_admissions' => $admissions->filter(fn ($a) => $this->inWindow($a->admission_at, $from, $to))->count(),
            'new_interconsults' => $admissions->where('care_type', 'INTERCONSULT')
                ->filter(fn ($a) => $this->inWindow($a->admission_at, $from, $to))->count(),
            'single_evaluations' => $admissions->where('followup_mode', 'SINGLE_EVALUATION')->count(),

            // Os dois eventos que o rótulo antigo "Altas/encerramentos" fundia
            // num número só — e que o schema separa de propósito.
            'neurology_closures' => $admissions
                ->filter(fn ($a) => $this->inWindow($a->neurology_followup_closed_at, $from, $to))->count(),
            'hospital_discharges' => $admissions
                ->filter(fn ($a) => $this->inWindow($a->hospital_discharge_at, $from, $to))->count(),

            'currently_active' => $admissions->where('status', 'ACTIVE')->count(),
        ];
    }

    /**
     * Mesmo conjunto de números para a janela imediatamente anterior, de igual
     * duração. Um indicador sozinho não diz se o serviço melhorou ou piorou;
     * é a variação que informa. Só existe quando há janela fechada — sem
     * data inicial não há "período anterior" definível.
     */
    private function previousPeriod(array $filters, bool $includeDeleted, ?Carbon $from, Carbon $to): ?array
    {
        if ($from === null) {
            return null;
        }

        $lengthDays = (int) $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay()->endOfDay();
        $prevFrom = $prevTo->copy()->subDays($lengthDays - 1)->startOfDay();

        $prevFilters = $filters;
        $prevFilters['date_from'] = $prevFrom->toDateString();
        $prevFilters['date_to'] = $prevTo->toDateString();

        $previous = AdmissionFilters::query($prevFilters, $includeDeleted)->get();
        $volume = $this->volume($previous, $prevFrom, $prevTo);

        return [
            'from' => $prevFrom->toDateString(),
            'to' => $prevTo->toDateString(),
            'episodes' => $volume['episodes'],
            'unique_patients' => $volume['unique_patients'],
            'new_admissions' => $volume['new_admissions'],
            'neurology_closures' => $volume['neurology_closures'],
            'neurology_patient_days' => $volume['neurology_patient_days'],
            'coverage_pct' => $this->visitCoverage($previous, $prevFrom, $prevTo)['coverage_pct'],
        ];
    }

    /**
     * Série mensal — é o que separa uma fotografia de um acompanhamento de
     * gestão. Sem janela explícita, mostra os 12 meses até hoje.
     */
    private function monthlySeries($admissions, ?Carbon $from, Carbon $to): array
    {
        $cursor = ($from ?? $to->copy()->subMonths(11))->copy()->startOfMonth();
        $series = [];

        // Teto de 24 meses: uma janela de 10 anos não deve render 120 colunas
        // ilegíveis nem 120 varreduras da coleção.
        while ($cursor->lte($to) && count($series) < 24) {
            $monthFrom = $cursor->copy()->startOfMonth();
            $monthTo = $cursor->copy()->endOfMonth();
            if ($monthTo->gt($to)) {
                $monthTo = $to->copy();
            }
            if ($from && $monthFrom->lt($from)) {
                $monthFrom = $from->copy();
            }

            $inMonth = $admissions->filter(fn ($a) => $this->clip($a, $monthFrom, $monthTo) !== null);

            $series[] = [
                'month' => $cursor->format('Y-m'),
                'label' => $cursor->format('m/y'),
                'episodes' => $inMonth->count(),
                'new_admissions' => $inMonth->filter(fn ($a) => $this->inWindow($a->admission_at, $monthFrom, $monthTo))->count(),
                'neurology_closures' => $inMonth->filter(fn ($a) => $this->inWindow($a->neurology_followup_closed_at, $monthFrom, $monthTo))->count(),
                'patient_days' => $inMonth->sum(fn ($a) => $this->clippedCalendarDays($a, $monthFrom, $monthTo)),
                'coverage_pct' => $this->visitCoverage($inMonth, $monthFrom, $monthTo)['coverage_pct'],
            ];

            $cursor->addMonth();
        }

        return $series;
    }

    private function payers($admissions, ?Carbon $from, Carbon $to): array
    {
        $byPayerType = [
            'PRIVATE' => $admissions->where('payer_type', 'PRIVATE')->count(),
            'HEALTH_PLAN' => $admissions->where('payer_type', 'HEALTH_PLAN')->count(),
            // Sem isto os dois números acima não fechavam com o total de
            // episódios e não havia como perceber a diferença na tela.
            'NOT_INFORMED' => $admissions->filter(fn ($a) => ! $a->payer_type)->count(),
        ];

        $byPlan = $admissions->where('payer_type', 'HEALTH_PLAN')
            ->groupBy(fn ($a) => $a->health_plan_name_snapshot ?? $a->healthPlan?->name ?? 'Não informado')
            ->map(fn ($group, $planName) => [
                'plan' => $planName,
                'episodes' => $group->count(),
                'patient_days' => $group->sum(fn ($a) => $this->clippedCalendarDays($a, $from, $to)),
                'median_followup_days' => Percentiles::summarize($group->map(fn ($a) => $this->followupDays($a))->all())['median'],
            ])
            ->sortByDesc('episodes')->values();

        return ['private_vs_plan' => $byPayerType, 'by_plan' => $byPlan];
    }

    /**
     * Distribuição por procedência — é o motivo de o campo ter vocabulário
     * fechado: só assim a série é comparável ao longo do tempo. Percorre as
     * chaves do vocabulário (e não os episódios) para que uma procedência
     * com zero casos apareça como 0 em vez de sumir do relatório.
     */
    private function origins($admissions, ?Carbon $from, Carbon $to): array
    {
        $byOrigin = [];

        foreach (Admission::ORIGINS as $code => $label) {
            $group = $admissions->where('origin', $code);

            $byOrigin[] = [
                'origin' => $code,
                'label' => $label,
                'episodes' => $group->count(),
                'patient_days' => $group->sum(fn ($a) => $this->clippedCalendarDays($a, $from, $to)),
                'median_followup_days' => Percentiles::summarize(
                    $group->map(fn ($a) => $this->followupDays($a))->all()
                )['median'],
            ];
        }

        return [
            'by_origin' => $byOrigin,
            'not_informed' => $admissions->filter(fn ($a) => $a->origin === null)->count(),
        ];
    }

    private function interconsults($admissions): array
    {
        $interconsults = $admissions->where('care_type', 'INTERCONSULT');

        $bySpecialty = $interconsults->groupBy(fn ($a) => $a->requestingSpecialty?->name ?? 'Não informado')
            ->map(fn ($group, $name) => ['specialty' => $name, 'count' => $group->count()])
            ->sortByDesc('count')->values();

        $responseTimes = $interconsults
            ->filter(fn ($a) => $a->consult_requested_at && $a->first_neurology_evaluation_at)
            ->map(fn ($a) => $this->elapsedDays($a->consult_requested_at, $a->first_neurology_evaluation_at))
            ->all();

        return [
            'count' => $interconsults->count(),
            'by_specialty' => $bySpecialty,
            'response_time_days' => Percentiles::summarize($responseTimes),
            // Quantas interconsultas ainda não têm 1ª avaliação registrada —
            // sem isto, uma mediana calculada sobre 2 de 40 casos parecia
            // representar as 40.
            'awaiting_first_evaluation' => $interconsults->filter(fn ($a) => ! $a->first_neurology_evaluation_at)->count(),
        ];
    }

    private function lengthOfStay($admissions, ?Carbon $from, Carbon $to): array
    {
        // Permanência é estatística de episódio COMPLETO: entra quem teve o
        // evento (alta / encerramento) dentro da janela, e o valor é a duração
        // inteira do episódio — recortar a duração aqui produziria uma
        // "permanência média" menor que a real só porque o mês acabou.
        $hospitalLos = $admissions
            ->filter(fn ($a) => $this->inWindow($a->hospital_discharge_at, $from, $to))
            ->map(fn ($a) => $this->elapsedDays($a->admission_at, $a->hospital_discharge_at))
            ->all();

        $closedInWindow = $admissions->filter(fn ($a) => $this->inWindow($a->neurology_followup_closed_at, $from, $to));

        return [
            'hospital_los_days' => Percentiles::summarize($hospitalLos),
            'neurology_followup_days' => Percentiles::summarize(
                $closedInWindow->map(fn ($a) => $this->followupDays($a))->all()
            ),
            // Quanto tempo os que SEGUEM abertos já acumulam — some da
            // estatística de permanência (ainda não terminaram) mas é o que
            // antecipa a longa permanência.
            'active_days_so_far' => Percentiles::summarize(
                $admissions->where('status', 'ACTIVE')->map(fn ($a) => $this->followupDays($a))->all()
            ),
        ];
    }

    /**
     * Cobertura de visita diária.
     *
     * O denominador é dia de CALENDÁRIO sob acompanhamento dentro da janela —
     * e não, como antes, a quantidade de linhas em daily_rounds. Uma linha só
     * nasce quando alguém atribui ou assina a visita, então o dia em que
     * ninguém tocou no paciente não gerava linha e sumia do denominador: a
     * cobertura dava 100% justamente quando a equipe tinha visitado pouco.
     *
     * Também deixou de olhar só episódio ATIVO. Num relatório de mês fechado
     * todo episódio já está encerrado, e o indicador vinha em branco.
     */
    private function visitCoverage($admissions, ?Carbon $from, Carbon $to): array
    {
        $expected = 0;
        $visited = 0;
        $assigned = 0;
        $fromDay = $from?->copy()->startOfDay();

        foreach ($admissions as $admission) {
            $expected += $this->clippedCalendarDays($admission, $from, $to);

            $roundsInWindow = $admission->dailyRounds->filter(function ($round) use ($fromDay, $to) {
                $date = $round->round_date->copy()->startOfDay();

                return ($fromDay === null || $date->gte($fromDay)) && $date->lte($to);
            });

            $visited += $roundsInWindow->filter(fn ($r) => $r->completed_at)
                ->map(fn ($r) => $r->round_date->toDateString())->unique()->count();

            $assigned += $roundsInWindow->filter(fn ($r) => $r->assigned_physician_id)
                ->map(fn ($r) => $r->round_date->toDateString())->unique()->count();
        }

        return [
            'expected_patient_days' => $expected,
            'visited_patient_days' => $visited,
            'assigned_patient_days' => $assigned,
            'coverage_pct' => $expected > 0 ? round($visited / $expected * 100, 1) : null,
        ];
    }

    /**
     * Bloco "hoje" — deliberadamente separado do relatório de período. Estes
     * números falam do plantão de agora e não obedecem ao filtro de datas;
     * misturados no card de cobertura, faziam "não visitados hoje" aparecer
     * dentro de um relatório de janeiro.
     */
    private function today(): array
    {
        $today = now()->toDateString();
        $active = Admission::active()->with('dailyRounds')->get();

        return [
            'date' => $today,
            'active_cases' => $active->count(),
            'unassigned' => $active->filter(fn ($a) => ! $a->dailyRounds->contains(
                fn ($r) => $r->round_date->toDateString() === $today && $r->assigned_physician_id
            ))->count(),
            'not_visited' => $active->filter(fn ($a) => ! $a->dailyRounds->contains(
                fn ($r) => $r->round_date->toDateString() === $today && $r->completed_at
            ))->count(),
        ];
    }

    private function diagnoses($admissions): array
    {
        $suspected = $admissions->flatMap(fn ($a) => $a->diagnoses->where('phase', 'SUSPECTED')->where('is_primary', true));
        $final = $admissions->flatMap(fn ($a) => $a->diagnoses->where('phase', 'FINAL')->where('is_primary', true));

        $top = fn ($collection) => $collection->groupBy('cid_code')
            ->map(fn ($group, $code) => [
                'cid_code' => $code,
                'description' => $group->first()->description_snapshot,
                'count' => $group->count(),
            ])
            ->sortByDesc('count')->take(10)->values();

        return [
            'top_suspected' => $top($suspected),
            'top_final' => $top($final),
        ];
    }

    private function diagnosticAgreement($admissions, ?Carbon $from, Carbon $to): array
    {
        $closed = $admissions->filter(fn ($a) => $this->inWindow($a->neurology_followup_closed_at, $from, $to));

        $closedWithBoth = $closed->filter(function ($a) {
            return $a->diagnoses->where('phase', 'SUSPECTED')->where('is_primary', true)->isNotEmpty()
                && $a->diagnoses->where('phase', 'FINAL')->where('is_primary', true)->isNotEmpty();
        });

        $concordant = 0;
        $changed = 0;
        $sameCategory = 0;

        foreach ($closedWithBoth as $a) {
            $suspectedCode = $a->diagnoses->where('phase', 'SUSPECTED')->where('is_primary', true)->first()->cid_code;
            $finalCode = $a->diagnoses->where('phase', 'FINAL')->where('is_primary', true)->first()->cid_code;

            if ($suspectedCode === $finalCode) {
                $concordant++;

                continue;
            }

            $changed++;
            // G40.9 → G40.8 é mudança de código, não de doença. Contar as duas
            // coisas como "mudou" exagera a taxa de mudança diagnóstica.
            if (substr($suspectedCode, 0, 3) === substr($finalCode, 0, 3)) {
                $sameCategory++;
            }
        }

        return [
            'concordant' => $concordant,
            'changed' => $changed,
            'changed_same_category' => $sameCategory,
            'undetermined' => $closed->count() - $closedWithBoth->count(),
            'evaluated' => $closedWithBoth->count(),
        ];
    }

    /**
     * Reinternação: intervalo entre o encerramento de um episódio e a entrada
     * do episódio seguinte do MESMO paciente. O evento contado é a nova
     * entrada, então só entram os pares cuja reentrada caiu dentro da janela —
     * antes, filtrar agosto ainda contabilizava uma reinternação de março.
     */
    private function readmissions($admissions, bool $includeDeleted, ?Carbon $from, Carbon $to): array
    {
        $patientIds = $admissions->pluck('patient_id')->unique();

        if ($patientIds->isEmpty()) {
            return ['within_7_days' => 0, 'within_30_days' => 0, 'transitions' => 0, 'note' => self::READMISSION_NOTE];
        }

        // O episódio ANTERIOR pode estar fora da janela — é dele que sai o
        // intervalo. Por isso a busca é por paciente, e o recorte se aplica
        // depois, sobre a reentrada.
        $query = Admission::whereIn('patient_id', $patientIds)
            ->orderBy('patient_id')->orderBy('admission_at');

        if ($includeDeleted) {
            $query->withTrashed();
        }

        $within7 = 0;
        $within30 = 0;
        $transitions = 0;

        foreach ($query->get()->groupBy('patient_id') as $episodes) {
            $episodes = $episodes->values();

            for ($i = 1; $i < $episodes->count(); $i++) {
                $reentry = $episodes[$i]->admission_at;
                if (! $this->inWindow($reentry, $from, $to)) {
                    continue;
                }

                $previousClosedAt = $episodes[$i - 1]->neurology_followup_closed_at;
                if ($previousClosedAt === null) {
                    continue;
                }

                $transitions++;
                $gapDays = $previousClosedAt->diffInDays($reentry, true);

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
            'transitions' => $transitions,
            'note' => self::READMISSION_NOTE,
        ];
    }

    private function pendingItems($admissions, ?Carbon $from, Carbon $to): array
    {
        $pending = $admissions->flatMap->pendingItems;
        $resolved = $pending->where('status', 'DONE')->filter(fn ($p) => $p->resolved_at);

        $resolutionDays = $resolved->map(fn ($p) => $this->elapsedDays($p->created_at, $p->resolved_at))->all();

        $openAtClosure = $admissions
            ->filter(fn ($a) => $this->inWindow($a->neurology_followup_closed_at, $from, $to))
            ->sum(fn ($a) => $a->pendingItems->where('status', 'OPEN')->count());

        return [
            'open' => $pending->where('status', 'OPEN')->count(),
            'created' => $pending->count(),
            'resolved' => $resolved->count(),
            'resolution_days' => Percentiles::summarize($resolutionDays),
            'open_at_closure' => $openAtClosure,
        ];
    }

    private function singleEvaluations($admissions): array
    {
        $single = $admissions->where('followup_mode', 'SINGLE_EVALUATION');

        $bySpecialty = $single->groupBy(fn ($a) => $a->requestingSpecialty?->name ?? 'Não informado')
            ->map(fn ($g, $name) => ['specialty' => $name, 'count' => $g->count()])
            ->sortByDesc('count')->values();

        $byPlan = $single->groupBy(fn ($a) => $a->payer_type === 'PRIVATE' ? 'Particular' : ($a->health_plan_name_snapshot ?? 'Não informado'))
            ->map(fn ($g, $name) => ['plan' => $name, 'count' => $g->count()])
            ->sortByDesc('count')->values();

        $withBothTimes = $single->filter(fn ($a) => $a->consult_requested_at && $a->first_neurology_evaluation_at);

        $sameDay = $withBothTimes->filter(
            fn ($a) => $a->consult_requested_at->toDateString() === $a->first_neurology_evaluation_at->toDateString()
        )->count();

        return [
            'count' => $single->count(),
            'by_specialty' => $bySpecialty,
            'by_plan' => $byPlan,
            'response_time_days' => Percentiles::summarize(
                $withBothTimes->map(fn ($a) => $this->elapsedDays($a->consult_requested_at, $a->first_neurology_evaluation_at))->all()
            ),
            'same_day_pct' => $withBothTimes->count() > 0 ? round($sameDay / $withBothTimes->count() * 100, 1) : null,
            'same_day_base' => $withBothTimes->count(),
            'converted_to_followup_note' => 'Conversões viram followup_mode=ONGOING e saem desta contagem — auditoria em audit_logs (UPDATE_ADMISSION).',
        ];
    }

    /**
     * Produtividade por médico, contada sobre visitas ASSINADAS no período.
     *
     * "Pacientes únicos" é paciente mesmo — antes contava episódios, e duas
     * internações do mesmo paciente viravam dois pacientes. "1ªs avaliações"
     * é autoria de verdade: quem assinou a PRIMEIRA visita do episódio, e não
     * "tocou num caso cujo campo está preenchido".
     */
    private function physicians($admissions, ?Carbon $from, Carbon $to): array
    {
        $patientByAdmission = $admissions->pluck('patient_id', 'id');
        $modeByAdmission = $admissions->pluck('followup_mode', 'id');

        // Quem assinou a primeira visita de cada episódio.
        $firstRoundAuthor = [];
        foreach ($admissions as $admission) {
            $first = $admission->dailyRounds->filter(fn ($r) => $r->completed_at)
                ->sortBy(fn ($r) => $r->completed_at->getTimestamp())->first();

            if ($first && $first->completed_by) {
                $firstRoundAuthor[$admission->id] = $first->completed_by;
            }
        }

        $fromDay = $from?->copy()->startOfDay();

        $rounds = $admissions->flatMap->dailyRounds->filter(function ($round) use ($fromDay, $to) {
            if (! $round->completed_by || ! $round->completed_at) {
                return false;
            }

            $date = $round->round_date->copy()->startOfDay();

            return ($fromDay === null || $date->gte($fromDay)) && $date->lte($to);
        });

        $byPhysician = $rounds->groupBy('completed_by')
            ->map(function ($group, $physicianId) use ($patientByAdmission, $modeByAdmission, $firstRoundAuthor) {
                $admissionIds = $group->pluck('admission_id')->unique();

                return [
                    'physician' => $group->first()->completer?->full_name ?? "Usuário #{$physicianId}",
                    'rounds' => $group->count(),
                    'episodes' => $admissionIds->count(),
                    'unique_patients' => $admissionIds->map(fn ($id) => $patientByAdmission[$id] ?? null)
                        ->filter()->unique()->count(),
                    'first_evaluations' => $admissionIds->filter(
                        fn ($id) => (int) ($firstRoundAuthor[$id] ?? 0) === (int) $physicianId
                    )->count(),
                    'single_evaluations' => $admissionIds->filter(
                        fn ($id) => ($modeByAdmission[$id] ?? null) === 'SINGLE_EVALUATION'
                    )->count(),
                ];
            })
            ->sortByDesc('rounds')->values();

        return ['by_physician' => $byPhysician];
    }

    // -----------------------------------------------------------------
    // Qualidade dos dados
    // -----------------------------------------------------------------

    /**
     * Lista operacional do "agora" — não segue o filtro de período do
     * relatório, e a tela diz isso. São pendências de cadastro para alguém
     * resolver hoje, não estatística de um mês passado.
     *
     * Contado por agregação no SQLite. Antes carregava em memória TODOS os
     * episódios encerrados da história inteira, com diagnósticos, a cada
     * abertura da tela — só para contar quantos não têm CID final. Esse custo
     * crescia para sempre.
     */
    public function dataQuality()
    {
        Gate::authorize('view-dashboards');

        $today = now()->toDateString();
        $active = fn () => Admission::query()->active();

        return response()->json([
            'active_without_suspected_diagnosis' => $active()
                ->whereDoesntHave('diagnoses', fn ($q) => $q->where('phase', 'SUSPECTED'))->count(),
            'interconsults_without_specialty' => $active()->where('care_type', 'INTERCONSULT')
                ->whereNull('requesting_specialty_id')->count(),
            'interconsults_without_request_time' => $active()->where('care_type', 'INTERCONSULT')
                ->whereNull('consult_requested_at')->count(),
            'without_responsible_today' => $active()->whereDoesntHave('dailyRounds', fn ($q) => $q
                ->whereDate('round_date', $today)->whereNotNull('assigned_physician_id'))->count(),
            'not_visited_today' => $active()->whereDoesntHave('dailyRounds', fn ($q) => $q
                ->whereDate('round_date', $today)->whereNotNull('completed_at'))->count(),
            'discharges_without_final_diagnosis' => Admission::query()->closed()
                ->whereDoesntHave('diagnoses', fn ($q) => $q->where('phase', 'FINAL'))->count(),
            'without_payer_defined' => $active()->whereNull('payer_type')->count(),
            'without_origin' => $active()->whereNull('origin')->count(),
            'admissions_over_30_days' => $active()
                ->whereDate('admission_at', '<', now()->subDays(30)->toDateString())->count(),
            'single_evaluations_open_over_3_days' => $active()->where('followup_mode', 'SINGLE_EVALUATION')
                ->whereDate('admission_at', '<', now()->subDays(3)->toDateString())->count(),
            'pending_items_open_over_14_days' => DB::table('pending_items')
                ->join('admissions', 'admissions.id', '=', 'pending_items.admission_id')
                ->whereNull('admissions.deleted_at')
                ->where('pending_items.status', 'OPEN')
                ->whereDate('pending_items.created_at', '<', now()->subDays(14)->toDateString())
                ->count(),
        ]);
    }
}
