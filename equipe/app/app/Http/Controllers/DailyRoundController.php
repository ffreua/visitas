<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\DailyRound;
use App\Services\AmhsBilling;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class DailyRoundController extends Controller
{
    /**
     * O responsável do dia é lido a partir do DailyRound de hoje; se não
     * existir, "Responsável hoje: NÃO DEFINIDO" (seção 34) — nunca apaga
     * o registro do dia anterior, apenas não existe um novo para hoje.
     *
     * Busca por whereDate() (não igualdade direta) porque o cast "date"
     * serializa com componente de hora ao salvar; uma busca por igualdade
     * de string quebraria o firstOrNew e duplicaria a linha do dia.
     */
    private function todaysRound(Admission $admission): DailyRound
    {
        $today = now()->toDateString();

        return $admission->dailyRounds()->whereDate('round_date', $today)->first()
            ?? $admission->dailyRounds()->make(['round_date' => $today]);
    }

    /**
     * As visitas do próprio médico, para conferência e faturamento.
     *
     * Recorte: as visitas que ELE ASSINOU (`completed_by`), não as que lhe
     * foram atribuídas — visita atribuída e não assinada não foi feita, e
     * portanto não se cobra. Como o filtro é sempre o próprio usuário
     * autenticado, não há aqui recorte por permissão a fazer: ninguém vê a
     * lista de outro médico por este endpoint.
     *
     * whereHas('admission') exclui episódio excluído (SoftDeletes): sem
     * isso a linha continuaria na conta com o paciente em branco.
     */
    public function mine(Request $request)
    {
        $this->authorize('viewAny', Admission::class);

        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $from = isset($data['date_from']) ? Carbon::parse($data['date_from'])->toDateString() : now()->startOfMonth()->toDateString();
        $to = isset($data['date_to']) ? Carbon::parse($data['date_to'])->toDateString() : now()->toDateString();

        $user = $request->user();

        // O teto existe só para que uma janela absurda não monte uma folha
        // de milhares de linhas em silêncio; `truncated` avisa a tela em vez
        // de a lista simplesmente terminar antes.
        $limit = 3000;

        $rounds = DailyRound::query()
            ->whereNotNull('completed_at')
            ->where('completed_by', $user->id)
            ->whereDate('round_date', '>=', $from)
            ->whereDate('round_date', '<=', $to)
            ->whereHas('admission')
            ->with(['admission.patient', 'admission.healthPlan', 'admission.diagnoses'])
            ->orderBy('round_date')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $truncated = $rounds->count() > $limit;
        $rounds = $rounds->take($limit);

        $rows = $rounds->map(function (DailyRound $round) {
            $admission = $round->admission;
            $cid = $this->billableDiagnosis($admission);

            $payer = $admission->payer_type === 'PRIVATE'
                ? 'Particular'
                : ($admission->health_plan_name_snapshot ?: $admission->healthPlan?->name);

            return [
                'round_id' => $round->id,
                'admission_id' => $admission->id,
                'round_date' => $round->round_date?->toDateString(),
                'patient_name' => $admission->patient?->full_name,
                'attendance_number' => $admission->attendance_number,
                'medical_record_number' => $admission->patient?->medical_record_number,
                'cid_code' => $cid?->cid_code,
                'cid_description' => $cid?->description_snapshot,
                'payer' => $payer,
                'amhs' => AmhsBilling::applies($admission->payer_type, $payer),
            ];
        })
            ->sortBy([
                ['round_date', 'asc'],
                ['patient_name', 'asc'],
            ])
            ->values();

        return response()->json([
            'physician' => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'crm' => $user->crm,
            ],
            'date_from' => $from,
            'date_to' => $to,
            'amhs_label' => AmhsBilling::LABEL,
            'truncated' => $truncated,
            'rows' => $rows,
        ]);
    }

    /**
     * O CID que representa o episódio na folha: o diagnóstico final
     * primário quando já existe (é o que fecha a conta), caindo para a
     * hipótese primária enquanto o caso está aberto. Sem inventar ordem:
     * primário antes de secundário, final antes de suspeito.
     */
    private function billableDiagnosis(Admission $admission)
    {
        $diagnoses = $admission->diagnoses;

        return $diagnoses->first(fn ($d) => $d->phase === 'FINAL' && $d->is_primary)
            ?? $diagnoses->first(fn ($d) => $d->phase === 'FINAL')
            ?? $diagnoses->first(fn ($d) => $d->phase === 'SUSPECTED' && $d->is_primary)
            ?? $diagnoses->first(fn ($d) => $d->phase === 'SUSPECTED')
            ?? $diagnoses->first();
    }

    public function assign(Request $request, Admission $admission)
    {
        $this->authorize('update', $admission);

        $data = $request->validate([
            'assigned_physician_id' => ['required', 'integer', Rule::exists('users', 'id')->where('active', true)],
        ]);

        $round = $this->todaysRound($admission);
        $round->assigned_physician_id = $data['assigned_physician_id'];
        $round->assigned_by = Auth::id();
        $round->assigned_at = now();
        $round->save();

        AuditLogger::log('ASSIGN_ROUND', 'DailyRound', $round->id);

        return response()->json($round->load(['assignedPhysician', 'completer']));
    }

    public function complete(Request $request, Admission $admission)
    {
        $this->authorize('update', $admission);

        $data = $request->validate([
            'daily_note' => ['nullable', 'string'],
        ]);

        $round = $this->todaysRound($admission);
        if (! $round->assigned_physician_id) {
            $round->assigned_physician_id = Auth::id();
            $round->assigned_by = Auth::id();
            $round->assigned_at = now();
        }
        $round->completed_by = Auth::id();
        $round->completed_at = now();
        if (isset($data['daily_note'])) {
            $round->daily_note = $data['daily_note'];
        }
        $round->save();

        // A primeira visita assinada do episódio É a primeira avaliação da
        // Neurologia. Antes disto o campo só era preenchido no encerramento
        // de avaliação única — ou seja, na interconsulta com acompanhamento
        // contínuo ele nunca preenchia, e o indicador de tempo de resposta
        // do serviço ficava permanentemente vazio. Grava-se uma vez só: é a
        // PRIMEIRA avaliação, não a mais recente.
        if (! $admission->first_neurology_evaluation_at) {
            // Gravação DIRETA, sem passar pelo model, de propósito: isto é um
            // carimbo derivado da visita, não uma edição do episódio por um
            // usuário. Um save() aqui dispararia o hook de updating e
            // incrementaria `version` — o optimistic lock que a tela já tem em
            // mãos ficaria velho, e quem assinasse a visita tomaria 409
            // ("atualizado por outro usuário") ao encerrar em seguida.
            Admission::whereKey($admission->getKey())
                ->update(['first_neurology_evaluation_at' => $round->completed_at]);

            $admission->first_neurology_evaluation_at = $round->completed_at;
            $admission->syncOriginalAttribute('first_neurology_evaluation_at');
        }

        AuditLogger::log('COMPLETE_ROUND', 'DailyRound', $round->id);

        return response()->json($round->load(['assignedPhysician', 'completer']));
    }
}
