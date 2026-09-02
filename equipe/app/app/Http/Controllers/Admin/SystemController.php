<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApplyMigrationsRequest;
use App\Http\Requests\DeleteBackupRequest;
use App\Http\Requests\ResetClinicalDataRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\BackupService;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
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
     * Listagem + o que a tela precisa para mostrar que os backups não
     * crescem sem limite: a política de retenção em vigor e o espaço
     * ocupado. A restauração continua exclusivamente via CLI
     * (`php artisan neurologia:restore`), ver seção 95 do PRD e o
     * comentário em App\Console\Commands\NeurologiaRestore.
     */
    public function backups(BackupService $service)
    {
        Gate::authorize('viewAny', User::class);

        $backups = $service->list();

        return response()->json([
            'backups' => $backups,
            'total_size' => array_sum(array_column($backups, 'size')),
            'retention' => config('neurologia.backup_retention'),
        ]);
    }

    /**
     * Exclusão manual de um backup. Existe porque a retenção automática só
     * roda quando um backup novo é criado — e nesta hospedagem, sem cron,
     * isso acontece raramente (atualização de estrutura, zona de perigo).
     * Sem esta rota, um arquivo antigo ocuparia espaço até o próximo
     * backup, sem nenhuma forma de removê-lo pelo painel.
     */
    public function deleteBackup(DeleteBackupRequest $request, BackupService $service, string $filename)
    {
        Gate::authorize('viewAny', User::class);

        if (! Hash::check($request->validated()['password'], $request->user()->password)) {
            return response()->json(['message' => 'Senha incorreta.'], 403);
        }

        try {
            $service->delete($filename);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Backup excluído.']);
    }

    /**
     * Aplica a política de retenção sob demanda, sem esperar o próximo
     * backup — usado para limpar de uma vez os arquivos acumulados.
     */
    public function pruneBackups(DeleteBackupRequest $request, BackupService $service)
    {
        Gate::authorize('viewAny', User::class);

        if (! Hash::check($request->validated()['password'], $request->user()->password)) {
            return response()->json(['message' => 'Senha incorreta.'], 403);
        }

        $removed = $service->applyRetention();

        return response()->json([
            'message' => $removed > 0
                ? "{$removed} backup(s) fora da política de retenção foram excluídos."
                : 'Nenhum backup fora da política de retenção — nada a excluir.',
            'removed' => $removed,
        ]);
    }

    /**
     * Quais migrations ainda não rodaram neste banco. Existe porque a
     * hospedagem do serviço não tem terminal: sem uma leitura pela web,
     * não haveria como saber se o banco em produção está na estrutura que
     * a versão do código instalada espera.
     */
    public function migrationStatus(Migrator $migrator)
    {
        Gate::authorize('viewAny', User::class);

        $files = $migrator->getMigrationFiles(
            array_merge($migrator->paths(), [database_path('migrations')])
        );

        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];
        $pending = array_values(array_diff(array_keys($files), $ran));

        return response()->json([
            'total' => count($files),
            'ran' => count($ran),
            'pending' => $pending,
            'up_to_date' => $pending === [],
        ]);
    }

    /**
     * Aplica as migrations pendentes com backup verificado antes — mesmo
     * contrato da Zona de Perigo: se o backup falhar, nada é executado.
     * Migrations do projeto só ADICIONAM estrutura (colunas/índices); os
     * dados clínicos já cadastrados são preservados.
     */
    public function applyMigrations(ApplyMigrationsRequest $request, BackupService $service, Migrator $migrator)
    {
        Gate::authorize('viewAny', User::class);

        if (! Hash::check($request->validated()['password'], $request->user()->password)) {
            return response()->json(['message' => 'Senha incorreta.'], 403);
        }

        $files = $migrator->getMigrationFiles(
            array_merge($migrator->paths(), [database_path('migrations')])
        );
        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];
        $pending = array_values(array_diff(array_keys($files), $ran));

        if ($pending === []) {
            return response()->json([
                'message' => 'O banco já está atualizado — nenhuma migration pendente.',
                'applied' => [],
                'safety_backup' => null,
            ]);
        }

        try {
            $safetyBackup = $service->create();
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => "ABORTADO: não foi possível criar/verificar o backup de segurança ({$e->getMessage()}). Nada foi alterado no banco.",
            ], 422);
        }

        try {
            Artisan::call('migrate', ['--force' => true]);
            $output = Artisan::output();
        } catch (\Throwable $e) {
            return response()->json([
                'message' => "A atualização falhou: {$e->getMessage()}. Backup de segurança criado antes da tentativa: {$safetyBackup['filename']}.",
                'safety_backup' => $safetyBackup['filename'],
            ], 500);
        }

        AuditLogger::log('APPLY_MIGRATIONS', 'Database', $safetyBackup['filename'], $pending);

        return response()->json([
            'message' => 'Estrutura do banco atualizada. Os dados já cadastrados foram preservados.',
            'applied' => $pending,
            'safety_backup' => $safetyBackup['filename'],
            'output' => trim($output),
        ]);
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
