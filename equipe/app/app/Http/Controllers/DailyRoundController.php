<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\DailyRound;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
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

        return response()->json($round->load('assignedPhysician'));
    }

    public function complete(Request $request, Admission $admission)
    {
        $this->authorize('update', $admission);

        $data = $request->validate([
            'daily_note' => ['nullable', 'string'],
        ]);

        $round = $this->todaysRound($admission);
        $round->completed_by = Auth::id();
        $round->completed_at = now();
        if (isset($data['daily_note'])) {
            $round->daily_note = $data['daily_note'];
        }
        $round->save();

        AuditLogger::log('COMPLETE_ROUND', 'DailyRound', $round->id);

        return response()->json($round->load('completer'));
    }
}
