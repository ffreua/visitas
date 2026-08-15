<?php

namespace App\Console\Commands;

use App\Services\DirectoryWriteCheck;
use Illuminate\Console\Command;

class NeurologiaPreflight extends Command
{
    protected $signature = 'neurologia:preflight';

    protected $description = 'Diagnóstico de compatibilidade do ambiente HostGator antes de instalar (seção 8 do PRD)';

    private const REQUIRED_EXTENSIONS = [
        'pdo', 'pdo_sqlite', 'sqlite3', 'openssl', 'mbstring', 'json', 'fileinfo', 'intl', 'session',
    ];

    public function handle(): int
    {
        $ok = true;

        $this->info('=== Verificação de ambiente — Neurologia Hospitalar ===');

        $phpVersion = PHP_VERSION;
        $phpOk = version_compare(PHP_VERSION, '8.2.0', '>=');
        $ok = $ok && $phpOk;
        $this->line(($phpOk ? '[OK] ' : '[FALHA] ')."PHP {$phpVersion} (mínimo 8.2)");

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $loaded = extension_loaded($extension);
            $ok = $ok && $loaded;
            $this->line(($loaded ? '[OK] ' : '[FALHA] ')."Extensão {$extension}");
        }

        $paths = [
            'equipe/data' => base_path('../data'),
            'equipe/backups' => base_path('../backups'),
            'storage (Laravel)' => storage_path(),
        ];

        foreach ($paths as $label => $path) {
            $writable = DirectoryWriteCheck::isWritable($path);
            $ok = $ok && $writable;
            $this->line(($writable ? '[OK] ' : '[FALHA] ')."Permissão de escrita: {$label} ({$path})");
        }

        $this->newLine();
        $this->line($ok
            ? 'Ambiente compatível. Este comando pode ser removido após a instalação.'
            : 'Ambiente com pendências — corrija os itens marcados [FALHA] antes de prosseguir.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
