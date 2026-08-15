<?php

namespace App\Console\Commands;

use App\Models\CID10;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Cid10Import extends Command
{
    /**
     * CSV esperado (com cabeçalho): code,description,chapter
     * Ex.: G40.9,"Epilepsia, não especificada","Doenças do sistema nervoso"
     */
    protected $signature = 'cid10:import {file : Caminho do CSV com colunas code,description,chapter}';

    protected $description = 'Importa a tabela CID-10 completa a partir de um CSV local (sem dependência de API externa)';

    public function handle(): int
    {
        $path = $this->argument('file');

        if (! is_readable($path)) {
            $this->error("Arquivo não encontrado ou sem permissão de leitura: {$path}");

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        if (! $header || array_diff(['code', 'description', 'chapter'], $header)) {
            $this->error('CSV deve conter as colunas: code, description, chapter');
            fclose($handle);

            return self::FAILURE;
        }

        $count = 0;

        DB::transaction(function () use ($handle, $header, &$count) {
            while (($row = fgetcsv($handle)) !== false) {
                $record = array_combine($header, $row);
                $code = strtoupper(trim($record['code']));

                CID10::updateOrCreate(
                    ['code' => $code],
                    [
                        'description' => trim($record['description']),
                        'category' => Str::substr($code, 0, 3),
                        'chapter' => trim($record['chapter']) ?: null,
                        'normalized_description' => Str::of($record['description'])->ascii()->lower()->toString(),
                    ]
                );

                $count++;
            }
        });

        fclose($handle);

        $this->info("Importação concluída: {$count} códigos CID-10 processados.");

        return self::SUCCESS;
    }
}
