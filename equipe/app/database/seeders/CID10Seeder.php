<?php

namespace Database\Seeders;

use App\Models\CID10;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CID10Seeder extends Seeder
{
    /**
     * Conjunto inicial de códigos frequentes em Neurologia hospitalar, para
     * desenvolvimento/testes. A tabela completa de CID-10 deve ser importada
     * em produção via `php artisan cid10:import {arquivo.csv}`.
     */
    public function run(): void
    {
        $codes = [
            ['G40.9', 'Epilepsia, não especificada', 'Doenças do sistema nervoso'],
            ['G40.0', 'Epilepsia e síndromes epilépticas idiopáticas relacionadas com localização', 'Doenças do sistema nervoso'],
            ['G45.9', 'Ataque isquêmico transitório cerebral, não especificado', 'Doenças do sistema nervoso'],
            ['I63.9', 'Infarto cerebral não especificado', 'Doenças do aparelho circulatório'],
            ['I61.9', 'Hemorragia intracerebral não especificada', 'Doenças do aparelho circulatório'],
            ['G20', 'Doença de Parkinson', 'Doenças do sistema nervoso'],
            ['G35', 'Esclerose múltipla', 'Doenças do sistema nervoso'],
            ['G93.1', 'Lesão encefálica anóxica não classificada em outra parte', 'Doenças do sistema nervoso'],
            ['G91.9', 'Hidrocefalia não especificada', 'Doenças do sistema nervoso'],
            ['G03.9', 'Meningite não especificada', 'Doenças do sistema nervoso'],
            ['G04.9', 'Encefalite, mielite e encefalomielite não especificadas', 'Doenças do sistema nervoso'],
            ['R56.8', 'Outras convulsões e as não especificadas', 'Sintomas e sinais anormais'],
            ['R55', 'Síncope e colapso', 'Sintomas e sinais anormais'],
            ['R41.0', 'Desorientação não especificada', 'Sintomas e sinais anormais'],
            ['R47.0', 'Disfasia e afasia', 'Sintomas e sinais anormais'],
            ['G62.9', 'Polineuropatia não especificada', 'Doenças do sistema nervoso'],
            ['G61.0', 'Síndrome de Guillain-Barré', 'Doenças do sistema nervoso'],
            ['G70.0', 'Miastenia gravis', 'Doenças do sistema nervoso'],
            ['F03', 'Demência não especificada', 'Transtornos mentais e comportamentais'],
            ['G30.9', 'Doença de Alzheimer não especificada', 'Doenças do sistema nervoso'],
            ['S06.9', 'Traumatismo intracraniano não especificado', 'Lesões, envenenamento e outras consequências de causas externas'],
            ['G43.9', 'Enxaqueca não especificada', 'Doenças do sistema nervoso'],
            ['G83.9', 'Síndrome paralítica não especificada', 'Doenças do sistema nervoso'],
            ['G96.9', 'Transtorno do sistema nervoso central, não especificado', 'Doenças do sistema nervoso'],
        ];

        foreach ($codes as [$code, $description, $chapter]) {
            CID10::updateOrCreate(
                ['code' => $code],
                [
                    'description' => $description,
                    'category' => Str::substr($code, 0, 3),
                    'chapter' => $chapter,
                    'normalized_description' => Str::of($description)->ascii()->lower()->toString(),
                ]
            );
        }
    }
}
