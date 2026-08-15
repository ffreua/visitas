<?php

namespace App\Console\Commands;

use App\Services\AuditLogger;
use App\Services\BackupRetentionPolicy;
use App\Services\DirectoryWriteCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class NeurologiaBackup extends Command
{
    protected $signature = 'neurologia:backup';

    protected $description = 'Cria um backup consistente do SQLite (checkpoint WAL + cópia + checksum) e aplica a retenção configurada';

    public function handle(BackupRetentionPolicy $policy): int
    {
        $databasePath = config('database.connections.sqlite.database');
        $backupsPath = rtrim(config('neurologia.backups_path'), '/\\');

        if (! is_file($databasePath)) {
            $this->error("Banco não encontrado em {$databasePath}");

            return self::FAILURE;
        }

        if (! DirectoryWriteCheck::isWritable($backupsPath)) {
            $this->error("Diretório de backups inexistente ou sem permissão de escrita: {$backupsPath}");

            return self::FAILURE;
        }

        // Garante que o conteúdo do WAL foi incorporado ao arquivo principal
        // antes de copiar — nunca copiar o .sqlite3 "cru" com um WAL ativo.
        DB::statement('PRAGMA wal_checkpoint(TRUNCATE);');

        $timestamp = now()->format('Y-m-d_His');
        $filename = "neurologia_{$timestamp}.sqlite3";
        $destination = "{$backupsPath}/{$filename}";

        if (! copy($databasePath, $destination)) {
            $this->error('Falha ao copiar o arquivo do banco.');

            return self::FAILURE;
        }

        $checksum = hash_file('sha256', $destination);
        $size = filesize($destination);

        AuditLogger::log('BACKUP', 'Database', $filename, [
            'size' => (string) $size,
            'checksum' => $checksum,
        ]);

        $this->info("Backup criado: {$filename} ({$size} bytes, sha256={$checksum})");

        $this->applyRetention($policy, $backupsPath);

        return self::SUCCESS;
    }

    private function applyRetention(BackupRetentionPolicy $policy, string $backupsPath): void
    {
        $files = glob("{$backupsPath}/neurologia_*.sqlite3") ?: [];

        $backups = [];
        foreach ($files as $path) {
            if (preg_match('/neurologia_(\d{4}-\d{2}-\d{2}_\d{6})\.sqlite3$/', basename($path), $m)) {
                $backups[] = ['path' => $path, 'timestamp' => Carbon::createFromFormat('Y-m-d_His', $m[1])];
            }
        }

        $toDelete = $policy->pathsToDelete($backups, now(), config('neurologia.backup_retention'));

        foreach ($toDelete as $path) {
            @unlink($path);
        }

        if (count($toDelete) > 0) {
            $this->line(count($toDelete).' backup(s) antigo(s) removido(s) pela política de retenção.');
        }
    }
}
