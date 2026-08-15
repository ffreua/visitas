<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ExportsCleanup extends Command
{
    protected $signature = 'exports:cleanup {--minutes=60 : Idade máxima antes de remover}';

    protected $description = 'Remove arquivos de exportação órfãos (gerados mas nunca baixados) — seção 126 do PRD';

    public function handle(): int
    {
        $exportsPath = rtrim(config('neurologia.exports_path'), '/\\');
        $maxAgeMinutes = (int) $this->option('minutes');
        $removed = 0;

        foreach (glob("{$exportsPath}/export_*.xlsx") ?: [] as $path) {
            if (now()->timestamp - filemtime($path) > $maxAgeMinutes * 60) {
                @unlink($path);
                $removed++;
            }
        }

        $this->info("{$removed} arquivo(s) de exportação órfão(s) removido(s).");

        return self::SUCCESS;
    }
}
