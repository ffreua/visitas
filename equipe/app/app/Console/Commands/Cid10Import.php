<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Cid10Import extends Command
{
    /**
     * Suporta CSV (colunas code,description,chapter) ou JSON (array de objetos).
     */
    protected $signature = 'cid10:import {file? : Caminho do arquivo JSON ou CSV (padrão: ../data/cid10.json)}';

    protected $description = 'Importa a tabela CID-10 a partir de um arquivo JSON ou CSV local';

    public function handle(): int
    {
        $path = $this->argument('file') ?: base_path('../data/cid10.json');

        if (! is_readable($path)) {
            $this->error("Arquivo não encontrado ou sem permissão de leitura: {$path}");

            return self::FAILURE;
        }

        $records = [];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($ext === 'json') {
            $json = json_decode(file_get_contents($path), true);
            if (! is_array($json)) {
                $this->error('JSON inválido ou corrompido.');

                return self::FAILURE;
            }

            foreach ($json as $item) {
                if (isset($item['code']) && isset($item['description'])) {
                    $code = strtoupper(trim($item['code']));
                    $desc = trim($item['description']);
                    $chapter = $item['chapter'] ?? null;
                } else {
                    $values = array_values($item);
                    if (count($values) < 2) {
                        continue;
                    }
                    $code = strtoupper(trim($values[0]));
                    $desc = trim($values[1]);
                    $chapter = isset($values[2]) ? trim($values[2]) : null;
                }

                if (! empty($code) && ! empty($desc)) {
                    $records[$code] = [
                        'code' => $code,
                        'description' => $desc,
                        'category' => Str::substr($code, 0, 3),
                        'chapter' => $chapter ?: null,
                        'normalized_description' => Str::of($desc)->ascii()->lower()->toString(),
                    ];
                }
            }
        } else {
            $handle = fopen($path, 'r');
            $header = fgetcsv($handle);

            if (! $header || array_diff(['code', 'description'], array_slice($header, 0, 2))) {
                $this->error('CSV deve conter as colunas: code, description (e opcionalmente chapter)');
                fclose($handle);

                return self::FAILURE;
            }

            while (($row = fgetcsv($handle)) !== false) {
                $item = array_combine($header, $row);
                $code = strtoupper(trim($item['code']));
                $desc = trim($item['description']);
                $chapter = isset($item['chapter']) ? trim($item['chapter']) : null;

                if (! empty($code) && ! empty($desc)) {
                    $records[$code] = [
                        'code' => $code,
                        'description' => $desc,
                        'category' => Str::substr($code, 0, 3),
                        'chapter' => $chapter ?: null,
                        'normalized_description' => Str::of($desc)->ascii()->lower()->toString(),
                    ];
                }
            }
            fclose($handle);
        }

        $total = count($records);
        $this->info("Importando {$total} códigos CID-10...");

        DB::transaction(function () use ($records) {
            foreach (array_chunk(array_values($records), 500) as $chunk) {
                DB::table('cid10')->upsert(
                    $chunk,
                    ['code'],
                    ['description', 'category', 'chapter', 'normalized_description']
                );
            }
        });

        $this->info("Importação concluída: {$total} códigos CID-10 processados com sucesso.");

        return self::SUCCESS;
    }
}
