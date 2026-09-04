<?php

namespace App\Http\Controllers;

use App\Http\Requests\CloseAdmissionRequest;
use App\Http\Requests\ForceDeleteAdmissionRequest;
use App\Http\Requests\SoftDeleteAdmissionRequest;
use App\Http\Requests\StoreAdmissionRequest;
use App\Http\Requests\UpdateAdmissionRequest;
use App\Models\Admission;
use App\Models\AdmissionDiagnosis;
use App\Models\CID10;
use App\Models\HealthPlan;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdmissionController extends Controller
{
    private const EAGER = ['patient', 'healthPlan', 'requestingSpecialty', 'diagnoses', 'pendingItems', 'dailyRounds.assignedPhysician', 'dailyRounds.completer'];

    private const ACTIVE_ADMISSION_CONFLICT_MESSAGE = 'Este paciente já possui acompanhamento ativo.';

    private const ATTENDANCE_NUMBER_CONFLICT_MESSAGE = 'Já existe um atendimento ativo com este número de atendimento.';

    public function index(Request $request)
    {
        $this->authorize('viewAny', Admission::class);

        $today = now()->toDateString();

        $query = Admission::query()->active()->with(self::EAGER);

        if ($search = $request->string('search')->toString()) {
            // Busca única cobrindo os três identificadores em uso na
            // enfermaria: nome, prontuário (paciente) e número de
            // atendimento (episódio) — quem está com a papeleta na mão
            // digita o que tem, sem escolher o campo antes.
            $query->where(function ($outer) use ($search) {
                $outer->whereHas('patient', function ($q) use ($search) {
                    $q->where('full_name', 'like', "%{$search}%")
                        ->orWhere('medical_record_number', 'like', '%'.strtoupper($search).'%');
                })->orWhere('attendance_number', 'like', '%'.strtoupper($search).'%');
            });
        }

        if ($careType = $request->string('care_type')->toString()) {
            $query->where('care_type', $careType);
        }

        if ($followupMode = $request->string('followup_mode')->toString()) {
            $query->where('followup_mode', $followupMode);
        }

        if ($request->boolean('unassigned_today')) {
            $query->whereDoesntHave('dailyRounds', fn ($q) => $q->whereDate('round_date', $today)->whereNotNull('assigned_physician_id'));
        }

        if ($request->boolean('not_visited_today')) {
            $query->whereDoesntHave('dailyRounds', fn ($q) => $q->whereDate('round_date', $today)->whereNotNull('completed_at'));
        }

        if ($request->boolean('with_pending')) {
            $query->whereHas('pendingItems', fn ($q) => $q->where('status', 'OPEN'));
        }

        $admissions = $query->orderByDesc('admission_at')->paginate(20);

        return response()->json($admissions);
    }

    public function closed(Request $request)
    {
        $this->authorize('viewAny', Admission::class);

        $admissions = Admission::query()->closed()->with(self::EAGER)
            ->orderByDesc('neurology_followup_closed_at')
            ->paginate(20);

        return response()->json($admissions);
    }

    public function trashed(Request $request)
    {
        $this->authorize('viewTrashed', Admission::class);

        $admissions = Admission::onlyTrashed()->with([...self::EAGER, 'deleter'])
            ->orderByDesc('deleted_at')
            ->paginate(20);

        return response()->json($admissions);
    }

    public function show(Admission $admission)
    {
        $this->authorize('view', $admission);

        return response()->json($admission->load([...self::EAGER, 'creator', 'updater']));
    }

    public function store(StoreAdmissionRequest $request)
    {
        $this->authorize('create', Admission::class);

        $data = $request->validated();

        try {
            $admission = DB::transaction(function () use ($data) {
                // Checagem dentro da transação (não antes dela) para que a
                // janela entre o SELECT e o INSERT fique protegida pela
                // serialização de escritas do SQLite — mas o índice único
                // parcial em admissions(patient_id) WHERE ACTIVE é quem
                // garante isso de verdade, mesmo sob busy_timeout/retry.
                if (Admission::where('patient_id', $data['patient_id'])->active()->exists()) {
                    throw ValidationException::withMessages([
                        'patient_id' => self::ACTIVE_ADMISSION_CONFLICT_MESSAGE,
                    ]);
                }

                $attendanceNumber = Admission::normalizeAttendanceNumber($data['attendance_number'] ?? null);
                if ($attendanceNumber !== null
                    && Admission::where('attendance_number', $attendanceNumber)->exists()) {
                    throw ValidationException::withMessages([
                        'attendance_number' => self::ATTENDANCE_NUMBER_CONFLICT_MESSAGE,
                    ]);
                }

                $healthPlanSnapshot = null;
                if ($data['payer_type'] === 'HEALTH_PLAN') {
                    $healthPlanSnapshot = HealthPlan::findOrFail($data['health_plan_id'])->name;
                }

                $admission = Admission::create([
                    ...collect($data)->except('suspected_cid_code')->toArray(),
                    'health_plan_name_snapshot' => $healthPlanSnapshot,
                    'created_by' => Auth::id(),
                ]);

                $cid = CID10::findOrFail($data['suspected_cid_code']);

                AdmissionDiagnosis::create([
                    'admission_id' => $admission->id,
                    'phase' => 'SUSPECTED',
                    'cid_code' => $cid->code,
                    'description_snapshot' => $cid->description,
                    'is_primary' => true,
                    'created_by' => Auth::id(),
                ]);

                return $admission;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(self::conflictMessagesFor($e));
        }

        // Recarrega para trazer colunas com default definido só no banco
        // (version) e nulas nunca atribuídas em memória (evita chaves
        // ausentes no JSON de resposta).
        $admission->refresh();

        AuditLogger::logModel('CREATE_ADMISSION', $admission);

        return response()->json($admission->load(self::EAGER), 201);
    }

    public function update(UpdateAdmissionRequest $request, Admission $admission)
    {
        $this->authorize('update', $admission);

        $data = $request->validated();
        $admission->assertVersion($data['version']);

        $changed = collect($data)->except('version')->keys()->values()->toArray();

        // O snapshot precisa acompanhar health_plan_id mesmo quando o
        // cliente manda só esse campo sem reenviar payer_type junto —
        // senão o episódio fica com health_plan_id novo mas o snapshot
        // (usado preferencialmente no dashboard e na exportação) continua
        // apontando pro plano antigo.
        if (($data['payer_type'] ?? null) === 'PRIVATE') {
            // Trocar convênio → particular precisa limpar o vínculo inteiro.
            // Zerar só o snapshot deixava health_plan_id apontando para o
            // plano antigo: o episódio ficava "particular" com um plano
            // pendurado, que voltaria a aparecer se alguém reabrisse a
            // edição ou lesse o relacionamento em vez do snapshot.
            $data['health_plan_id'] = null;
            $data['health_plan_name_snapshot'] = null;
        } elseif (array_key_exists('health_plan_id', $data)) {
            $data['health_plan_name_snapshot'] = $data['health_plan_id']
                ? HealthPlan::findOrFail($data['health_plan_id'])->name
                : null;
        }

        $admission->fill(collect($data)->except('version')->toArray());
        $admission->updated_by = Auth::id();

        try {
            $admission->save();
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(self::conflictMessagesFor($e));
        }

        AuditLogger::logModel('UPDATE_ADMISSION', $admission, $changed);

        return response()->json($admission->load(self::EAGER));
    }

    /**
     * Dados do paciente sem os quais o episódio não serve para análise:
     * o prontuário é a chave que liga as internações da mesma pessoa, e a
     * data de nascimento é o que permite qualquer leitura por faixa etária.
     *
     * Diagnóstico final e desfecho não entram aqui porque já são
     * obrigatórios na própria CloseAdmissionRequest.
     *
     * @return array<int, string>
     */
    public static function missingForClosure(Admission $admission): array
    {
        $patient = $admission->patient;
        $faltando = [];

        if ($patient === null || $patient->medical_record_number === null) {
            $faltando[] = 'número de prontuário';
        }

        if ($patient === null || $patient->date_of_birth === null) {
            $faltando[] = 'data de nascimento';
        }

        return $faltando;
    }

    /**
     * Duas restrições únicas podem estourar no mesmo INSERT/UPDATE (um
     * episódio ativo por paciente e um número de atendimento por episódio
     * vivo) — sem separar, um número de atendimento repetido apareceria na
     * tela como "paciente já tem acompanhamento ativo".
     *
     * @return array<string, string>
     */
    private static function conflictMessagesFor(UniqueConstraintViolationException $e): array
    {
        if (str_contains($e->getMessage(), 'attendance_number')) {
            return ['attendance_number' => self::ATTENDANCE_NUMBER_CONFLICT_MESSAGE];
        }

        return ['patient_id' => self::ACTIVE_ADMISSION_CONFLICT_MESSAGE];
    }

    public function close(CloseAdmissionRequest $request, Admission $admission)
    {
        $this->authorize('update', $admission);

        $data = $request->validated();
        $admission->assertVersion($data['version']);

        if ($admission->status === 'CLOSED') {
            throw ValidationException::withMessages(['status' => 'Este acompanhamento já está encerrado.']);
        }

        // O encerramento é o ponto em que o episódio deixa de ser assistencial
        // e vira dado de gestão — depois disso ninguém volta para completar o
        // cadastro. É aqui, e não no cadastro inicial, que os identificadores
        // do paciente são cobrados.
        if ($faltando = self::missingForClosure($admission)) {
            throw ValidationException::withMessages([
                'closure_requirements' => 'Complete o cadastro do paciente antes de encerrar: '
                    .implode(', ', $faltando).'.',
            ]);
        }

        $closedAt = $data['neurology_followup_closed_at'] ?? now();

        DB::transaction(function () use ($admission, $data, $closedAt) {
            $cid = CID10::findOrFail($data['final_cid_code']);

            AdmissionDiagnosis::create([
                'admission_id' => $admission->id,
                'phase' => 'FINAL',
                'cid_code' => $cid->code,
                'description_snapshot' => $cid->description,
                'is_primary' => true,
                'created_by' => Auth::id(),
            ]);

            $admission->neurology_followup_closed_at = $closedAt;

            // Só grava se veio preenchida — reencerrar não pode apagar uma
            // alta já registrada.
            if (! empty($data['hospital_discharge_at'])) {
                $admission->hospital_discharge_at = $data['hospital_discharge_at'];
            }

            $admission->discharge_outcome = $data['discharge_outcome'];
            $admission->followup_plan_documented = $data['followup_plan_documented'] ?? null;
            $admission->status = 'CLOSED';

            if ($admission->isSingleEvaluation() && ! $admission->first_neurology_evaluation_at) {
                $admission->first_neurology_evaluation_at = $closedAt;
            }

            $admission->updated_by = Auth::id();
            $admission->save();
        });

        $action = $admission->isSingleEvaluation() ? 'COMPLETE_SINGLE_EVALUATION' : 'CLOSE_FOLLOWUP';

        // Registra a conferência do convênio junto do encerramento: numa
        // discussão de faturamento depois, a pergunta é "quem disse que o
        // pagador estava certo, e quando" — e a resposta fica aqui.
        AuditLogger::logModel($action, $admission, ['health_plan_confirmed']);

        return response()->json($admission->load(self::EAGER));
    }

    public function convertToFollowup(Admission $admission)
    {
        $this->authorize('update', $admission);

        if (! $admission->isSingleEvaluation() || $admission->status !== 'ACTIVE') {
            throw ValidationException::withMessages([
                'followup_mode' => 'Somente avaliações únicas ainda ativas podem ser convertidas.',
            ]);
        }

        $admission->followup_mode = 'ONGOING';
        $admission->updated_by = Auth::id();
        $admission->save();

        AuditLogger::logModel('UPDATE_ADMISSION', $admission, ['followup_mode']);

        return response()->json($admission->load(self::EAGER));
    }

    public function destroy(SoftDeleteAdmissionRequest $request, Admission $admission)
    {
        $this->authorize('delete', $admission);

        $data = $request->validated();
        $reasonLabel = match ($data['reason']) {
            'DUPLICATE' => 'Cadastro duplicado',
            'NOT_NEUROLOGY' => 'Paciente não pertence à Neurologia',
            'CREATED_BY_MISTAKE' => 'Criado por engano',
            default => $data['reason_detail'] ?? 'Outro',
        };

        $admission->deleted_by = Auth::id();
        $admission->deletion_reason = $reasonLabel;
        $admission->save();
        $admission->delete();

        AuditLogger::logModel('SOFT_DELETE', $admission);

        return response()->json(['message' => 'Atendimento removido da lista assistencial.']);
    }

    public function restore(Admission $trashedAdmission)
    {
        $this->authorize('restore', $trashedAdmission);

        try {
            DB::transaction(function () use ($trashedAdmission) {
                // Sem isso, restaurar um episódio excluído poderia deixar o
                // paciente com dois episódios ACTIVE ao mesmo tempo (ex.:
                // médico excluiu o episódio A por engano, criou o B, e só
                // depois o admin restaura A pela tela de excluídos).
                if ($trashedAdmission->status === 'ACTIVE'
                    && Admission::where('patient_id', $trashedAdmission->patient_id)->active()->exists()) {
                    throw ValidationException::withMessages([
                        'patient_id' => self::ACTIVE_ADMISSION_CONFLICT_MESSAGE.' Encerre ou exclua o episódio atual antes de restaurar este.',
                    ]);
                }

                $trashedAdmission->restore();
                $trashedAdmission->deleted_by = null;
                $trashedAdmission->deletion_reason = null;
                $trashedAdmission->save();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'patient_id' => self::ACTIVE_ADMISSION_CONFLICT_MESSAGE.' Encerre ou exclua o episódio atual antes de restaurar este.',
            ]);
        }

        AuditLogger::logModel('RESTORE', $trashedAdmission);

        return response()->json($trashedAdmission->load(self::EAGER));
    }

    public function forceDestroy(ForceDeleteAdmissionRequest $request, Admission $trashedAdmission)
    {
        $this->authorize('forceDelete', $trashedAdmission);

        if (! Hash::check($request->string('password'), Auth::user()->password)) {
            return response()->json(['message' => 'Senha incorreta.'], 403);
        }

        $entityId = $trashedAdmission->uuid;

        AuditLogger::log('PERMANENT_DELETE', 'Admission', $entityId, ['reason' => $request->string('reason')->toString()]);

        $trashedAdmission->forceDelete();

        return response()->json(['message' => 'Registro excluído definitivamente.']);
    }
}
