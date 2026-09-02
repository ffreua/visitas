<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class NeurologiaBackup extends Command
{
    protected $signature = 'neurologia:backup';

    protected $description = 'Cria um backup consistente do SQLite (checkpoint WAL + cópia + checksum) e aplica a retenção configurada';

    public function handle(BackupService $service): int
    {
        try {
            $result = $service->create();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Backup criado: {$result['filename']} ({$result['size']} bytes, sha256={$result['checksum']})");

        // A retenção roda dentro de create() — vale para o backup criado
        // aqui e para os criados pelo painel, que é onde eles realmente
        // nascem nesta hospedagem (sem cron).
        if ($result['pruned'] > 0) {
            $this->line("{$result['pruned']} backup(s) antigo(s) removido(s) pela política de retenção.");
        }

        return self::SUCCESS;
    }
}
