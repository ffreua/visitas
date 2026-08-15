<?php

namespace Tests\Feature;

use App\Models\CID10;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OptimisticLockingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Seção 98 do PRD: edição concorrente nunca deve sobrescrever
     * silenciosamente — a segunda gravação com versão desatualizada falha.
     */
    public function test_stale_version_update_returns_409(): void
    {
        $this->actingAs(User::factory()->create());

        CID10::create(['code' => 'G40.9', 'description' => 'Epilepsia', 'category' => 'G40', 'normalized_description' => 'epilepsia']);
        $patient = Patient::create([
            'medical_record_number' => '222333',
            'full_name' => 'Paciente Concorrência',
            'date_of_birth' => '1990-01-01',
        ]);

        $admission = $this->postJson('/api/admissions', [
            'patient_id' => $patient->id,
            'admission_at' => now()->toDateTimeString(),
            'care_type' => 'INSTITUTIONAL',
            'followup_mode' => 'ONGOING',
            'payer_type' => 'PRIVATE',
            'suspected_cid_code' => 'G40.9',
        ])->assertCreated()->json();

        $this->assertSame(1, $admission['version']);

        // Primeira gravação, com a versão correta (1) — sucede e avança para 2.
        $this->putJson("/api/admissions/{$admission['id']}", [
            'version' => 1,
            'unit' => 'Enfermaria 4',
        ])->assertOk()->assertJsonPath('version', 2);

        // Segunda gravação, ainda usando a versão antiga (1) — deve falhar com 409.
        $this->putJson("/api/admissions/{$admission['id']}", [
            'version' => 1,
            'unit' => 'Enfermaria 9',
        ])->assertStatus(409);
    }
}
