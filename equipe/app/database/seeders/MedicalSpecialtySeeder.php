<?php

namespace Database\Seeders;

use App\Models\MedicalSpecialty;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MedicalSpecialtySeeder extends Seeder
{
    public function run(): void
    {
        $specialties = [
            'Cardiologia', 'Cirurgia Geral', 'Cirurgia Vascular', 'Clínica Médica',
            'Endocrinologia', 'Gastroenterologia', 'Geriatria', 'Ginecologia e Obstetrícia',
            'Hematologia', 'Infectologia', 'Nefrologia', 'Neurocirurgia', 'Oncologia',
            'Ortopedia e Traumatologia', 'Otorrinolaringologia', 'Pneumologia',
            'Psiquiatria', 'Radiologia', 'Reumatologia', 'Terapia Intensiva', 'Urologia',
        ];

        foreach ($specialties as $name) {
            MedicalSpecialty::firstOrCreate(
                ['normalized_name' => Str::of($name)->ascii()->lower()->toString()],
                ['name' => $name, 'active' => true]
            );
        }
    }
}
