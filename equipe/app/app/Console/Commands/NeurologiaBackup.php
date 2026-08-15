<?php

namespace App\Console\Commands;

use App\Services\BackupRetentionPolicy;
use App\Services\BackupService;
use Illuminate\Console\Command;

class NeurologiaBackup extends Command
{
    protected $signature = 'neurologia:backup';

    protected $description = 'Cria um backup consistente do SQLite (checkpoint WAL + cópia + checksum) e aplica a retenção configurada';

    public function handle(BackupService $service, BackupRetentionPolicy $policy): int
    {
        try {
            $result = $service->create();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Backup criado: {$result['filename']} ({$result['size']} bytes, sha256={$result['checksum']})");

        $removed = $service->applyRetention($policy);
        if ($removed > 0) {
            $this->line("{$removed} backup(s) antigo(s) removido(s) pela política de retenção.");
        }

        return self::SUCCESS;
    }
}
