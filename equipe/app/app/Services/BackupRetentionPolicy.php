<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Decide quais backups manter dado um esquema diário/semanal/mensal
 * (seção 94 do PRD). Não apaga nada por conta própria — apenas calcula.
 */
class BackupRetentionPolicy
{
    /**
     * @param  array<int, array{path: string, timestamp: Carbon}>  $backups
     * @param  array{daily: int, weekly: int, monthly: int}  $retention
     * @return array<int, string> Caminhos a remover.
     */
    public function pathsToDelete(array $backups, Carbon $now, array $retention): array
    {
        usort($backups, fn ($a, $b) => $b['timestamp'] <=> $a['timestamp']);

        // Cada faixa (diária/semanal/mensal) escolhe seus representantes de
        // forma independente das outras — sempre o backup mais recente por
        // "balde" (dia/semana/mês) dentro da janela. Só depois mesclamos os
        // três conjuntos. Calcular isso de forma interdependente (pulando
        // um backup só porque outra faixa já o manteve) deixaria o balde
        // "vago" e um backup mais antigo roubaria a vaga por engano.
        $daily = $this->representativesFor(
            $backups,
            fn ($ts) => $ts->format('Y-m-d'),
            fn ($ts) => $now->diffInDays($ts->copy()->startOfDay(), true),
            $retention['daily']
        );

        $weekly = $this->representativesFor(
            $backups,
            fn ($ts) => $ts->format('o-\WW'),
            fn ($ts) => $now->diffInWeeks($ts, true),
            $retention['weekly']
        );

        $monthly = $this->representativesFor(
            $backups,
            fn ($ts) => $ts->format('Y-m'),
            fn ($ts) => $now->diffInMonths($ts, true),
            $retention['monthly']
        );

        $keep = array_unique(array_merge($daily, $weekly, $monthly));
        $allPaths = array_column($backups, 'path');

        return array_values(array_diff($allPaths, $keep));
    }

    /**
     * @param  array<int, array{path: string, timestamp: Carbon}>  $backups
     * @return array<int, string>
     */
    private function representativesFor(array $backups, \Closure $bucketKey, \Closure $ageFn, int $windowSize): array
    {
        $representatives = [];

        foreach ($backups as $backup) {
            if ($ageFn($backup['timestamp']) > $windowSize) {
                continue;
            }

            $bucket = $bucketKey($backup['timestamp']);
            // $backups já vem ordenado do mais recente para o mais antigo,
            // então o primeiro a reivindicar um balde é o representante.
            $representatives[$bucket] ??= $backup['path'];
        }

        return array_values($representatives);
    }
}
