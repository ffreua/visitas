<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Criação e verificação de backups do SQLite — compartilhada entre o
 * comando `neurologia:backup` e a Zona de Perigo (que exige um backup
 * verificado antes de zerar dados clínicos, seção 97 do PRD).
 */
class BackupService
{
    /**
     * @return array{path: string, filename: string, checksum: string, size: int}
     *
     * @throws \RuntimeException
     */
    public function create(): array
    {
        $databasePath = $this->currentDatabasePath();
        $backupsPath = rtrim(config('neurologia.backups_path'), '/\\');

        if (! is_file($databasePath)) {
            throw new \RuntimeException("Banco não encontrado em {$databasePath}");
        }

        if (! DirectoryWriteCheck::isWritable($backupsPath)) {
            throw new \RuntimeException("Diretório de backups inexistente ou sem permissão de escrita: {$backupsPath}");
        }

        // Garante que o conteúdo do WAL foi incorporado ao arquivo principal
        // antes de copiar — nunca copiar o .sqlite3 "cru" com um WAL ativo.
        DB::statement('PRAGMA wal_checkpoint(TRUNCATE);');

        $filename = 'neurologia_'.now()->format('Y-m-d_His').'.sqlite3';
        $destination = "{$backupsPath}/{$filename}";

        if (! copy($databasePath, $destination)) {
            throw new \RuntimeException('Falha ao copiar o arquivo do banco.');
        }

        if (! $this->integrityCheck($destination)) {
            @unlink($destination);
            throw new \RuntimeException('Backup gerado falhou no integrity_check — removido, nada foi preservado.');
        }

        $checksum = hash_file('sha256', $destination);
        $size = filesize($destination);

        AuditLogger::log('BACKUP', 'Database', $filename, [
            'size' => (string) $size,
            'checksum' => $checksum,
        ]);

        return ['path' => $destination, 'filename' => $filename, 'checksum' => $checksum, 'size' => $size];
    }

    /**
     * Caminho do arquivo SQLite da conexão padrão ATUAL — não hardcoda o
     * nome "sqlite" para funcionar corretamente mesmo se a conexão padrão
     * for trocada dinamicamente (ex: isolamento de testes).
     */
    private function currentDatabasePath(): string
    {
        $connectionName = config('database.default');

        return config("database.connections.{$connectionName}.database");
    }

    /**
     * Roda PRAGMA integrity_check numa conexão SQLite dedicada e temporária
     * ao arquivo informado — não mexe na conexão da aplicação em uso.
     */
    public function integrityCheck(string $sqlitePath): bool
    {
        try {
            $pdo = new \PDO('sqlite:'.$sqlitePath);
            $result = $pdo->query('PRAGMA integrity_check;')->fetchColumn();

            return $result === 'ok';
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function applyRetention(BackupRetentionPolicy $policy): int
    {
        $backupsPath = rtrim(config('neurologia.backups_path'), '/\\');
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

        return count($toDelete);
    }

    /**
     * @return array<int, array{filename: string, size: int, created_at: string, checksum: string}>
     */
    public function list(): array
    {
        $backupsPath = rtrim(config('neurologia.backups_path'), '/\\');
        $files = glob("{$backupsPath}/neurologia_*.sqlite3") ?: [];

        $backups = array_map(fn ($path) => [
            'filename' => basename($path),
            'size' => filesize($path),
            'created_at' => Carbon::createFromTimestamp(filemtime($path))->toIso8601String(),
            'checksum' => hash_file('sha256', $path),
        ], $files);

        usort($backups, fn ($a, $b) => $b['created_at'] <=> $a['created_at']);

        return $backups;
    }
}
