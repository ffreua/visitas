<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResetClinicalDataRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\BackupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

class SystemController extends Controller
{
    public function integrityCheck()
    {
        Gate::authorize('viewAny', User::class);

        $result = DB::select('PRAGMA integrity_check;');

        return response()->json(['result' => $result]);
    }

    /**
     * Só leitura — a restauração em si é exclusivamente via CLI
     * (`php artisan neurologia:restore`), ver seção 95 do PRD e o
     * comentário em App\Console\Commands\NeurologiaRestore.
     */
    public function backups(BackupService $service)
    {
        Gate::authorize('viewAny', User::class);

        return response()->json($service->list());
    }

    /**
     * Zona de perigo (seções 96-97): apaga episódios/pacientes, preserva
     * usuários/CID/especialidades/planos/schema. Backup verificado é
     * obrigatório antes — se falhar, aborta sem apagar nada.
     */
    public function resetClinicalData(ResetClinicalDataRequest $request, BackupService $service)
    {
        Gate::authorize('viewAny', User::class);

        $data = $request->validated();

        if (! Hash::check($data['password'], $request->user()->password)) {
            return response()->json(['message' => 'Senha incorreta.'], 403);
        }

        try {
            $safetyBackup = $service->create();
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => "ABORTADO: não foi possível criar/verificar o backup de segurança ({$e->getMessage()}). Nenhum dado foi apagado.",
            ], 422);
        }

        DB::transaction(function () {
            // Ordem importa: admissions primeiro (cascade em diagnoses/
            // pending_items/daily_rounds), patients depois — patients tem
            // restrictOnDelete em admissions.patient_id.
            DB::table('admissions')->delete();
            DB::table('patients')->delete();
        });

        AuditLogger::log('RESET_DATABASE', 'Database', $safetyBackup['filename'], [
            'safety_backup' => $safetyBackup['filename'],
        ]);

        return response()->json([
            'message' => 'Dados clínicos zerados. Usuários, CID-10, especialidades e planos de saúde foram preservados.',
            'safety_backup' => $safetyBackup['filename'],
        ]);
    }
}
