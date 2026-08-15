<?php

namespace Database\Seeders;

use App\Models\HealthPlan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class HealthPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            'Bradesco Saúde', 'SulAmérica', 'Amil', 'Porto Saúde', 'Unimed',
            'NotreDame Intermédica', 'Hapvida', 'Golden Cross', 'Care Plus',
            'Omint', 'Allianz Saúde', 'Prevent Senior',
        ];

        foreach ($plans as $name) {
            HealthPlan::firstOrCreate(
                ['normalized_name' => Str::of($name)->ascii()->lower()->toString()],
                ['name' => $name, 'active' => true]
            );
        }
    }
}
