<?php

namespace App\Console\Commands;

use App\Services\AuditLogger;
use App\Services\BackupService;
use Illuminate\Console\Command;

class NeurologiaRestore extends Command
{
    /**
     * Restore é feito só via CLI, nunca por rota web — o PRD (seção 95)
     * permite explicitamente essa saída quando o restore via navegador não
     * pode ser feito com segurança. Trocar o arquivo do SQLite "por baixo"
     * de uma aplicação web ativa (outros workers PHP-FPM podem ter conexões
     * abertas para o arquivo antigo) é um risco que só faz sentido correr
     * com acesso direto ao servidor, fora do horário de maior uso.
     */
    protected $signature = 'neurologia:restore {backup : nome do arquivo em equipe/backups} {--force : pula a confirmação interativa}';

    protected $description = 'Restaura o SQLite a partir de um backup, com verificação de integridade e backup de segurança automático';

    public function handle(BackupService $service): int
    {
        $backupsPath = rtrim(config('neurologia.backups_path'), '/\\');
        $filename = basename($this->argument('backup'));
        $sourcePath = "{$backupsPath}/{$filename}";

        if (! is_file($sourcePath)) {
            $this->error("Backup não encontrado: {$filename}");

            return self::FAILURE;
        }

        if (! $service->integrityCheck($sourcePath)) {
            $this->error('O backup escolhido falhou no PRAGMA integrity_check. Restauração abortada — nada foi alterado.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(
            "Isso vai SUBSTITUIR o banco atual pelo backup \"{$filename}\". Um backup de segurança do estado atual será criado antes. Continuar?"
        )) {
            $this->line('Operação cancelada.');

            return self::SUCCESS;
        }

        $this->info('Criando backup de segurança do estado atual antes de restaurar...');
        try {
            $safety = $service->create();
            $this->info("Backup de segurança criado: {$safety['filename']}");
        } catch (\RuntimeException $e) {
            $this->error("ABORTADO: não foi possível criar o backup de segurança prévio ({$e->getMessage()}). Nada foi restaurado.");

            return self::FAILURE;
        }

        $liveDbPath = config('database.connections.'.config('database.default').'.database');

        // Remove sidecars do WAL do banco atual — não podem ser "replayed"
        // por engano contra o arquivo restaurado.
        @unlink($liveDbPath.'-wal');
        @unlink($liveDbPath.'-shm');

        if (! copy($sourcePath, $liveDbPath)) {
            $this->error('Falha ao copiar o backup para o caminho do banco ativo.');

            return self::FAILURE;
        }

        if (! $service->integrityCheck($liveDbPath)) {
            $this->error('O banco restaurado falhou no integrity_check pós-restauração. Restaure manualmente o backup de segurança '.$safety['filename'].' o quanto antes.');

            return self::FAILURE;
        }

        AuditLogger::log('RESTORE_BACKUP', 'Database', $filename, ['safety_backup' => $safety['filename']]);

        $this->info("Restauração concluída a partir de {$filename}.");
        $this->warn('Se a aplicação estiver rodando sob PHP-FPM/Apache, reinicie o serviço para garantir que nenhum worker mantenha uma conexão aberta com o arquivo anterior.');

        return self::SUCCESS;
    }
}
