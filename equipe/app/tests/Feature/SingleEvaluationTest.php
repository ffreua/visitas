<?php

namespace Tests\Feature;

use App\Models\CID10;
use App\Models\MedicalSpecialty;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SingleEvaluationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Critical test — seção 109 do PRD.
     */
    public function test_completing_single_evaluation_closes_followup_and_leaves_active_list(): void
    {
        $this->actingAs(User::factory()->create());

        CID10::create(['code' => 'G40.9', 'description' => 'Epilepsia', 'category' => 'G40', 'normalized_description' => 'epilepsia']);
        CID10::create(['code' => 'R55', 'description' => 'Síncope', 'category' => 'R55', 'normalized_description' => 'sincope']);

        $patient = Patient::create([
            'medical_record_number' => '777888',
            'full_name' => 'Paciente Avaliação Única',
            'date_of_birth' => '1990-01-01',
        ]);

        $created = $this->postJson('/api/admissions', [
            'patient_id' => $patient->id,
            'admission_at' => now()->toDateTimeString(),
            'care_type' => 'INTERCONSULT',
            'requesting_specialty_id' => MedicalSpecialty::create(['name' => 'Cardiologia', 'normalized_name' => 'cardiologia', 'active' => true])->id,
            'consult_requested_at' => now()->toDateTimeString(),
            'followup_mode' => 'SINGLE_EVALUATION',
            'payer_type' => 'PRIVATE',
            'suspected_cid_code' => 'G40.9',
        ])->assertCreated()->json();

        $this->assertSame('ACTIVE', $created['status']);
        $this->assertNull($created['first_neurology_evaluation_at']);

        $activeList = $this->getJson('/api/admissions')->json();
        $this->assertContains($created['id'], array_column($activeList['data'], 'id'));

        $closed = $this->postJson("/api/admissions/{$created['id']}/close", [
            'version' => $created['version'],
            'final_cid_code' => 'R55',
            'discharge_outcome' => 'Síncope vasovagal, sem indicação de seguimento.',
        ])->assertOk()->json();

        $this->assertSame('CLOSED', $closed['status']);
        $this->assertNotNull($closed['first_neurology_evaluation_at']);
        $this->assertNotNull($closed['neurology_followup_closed_at']);

        $activeListAfter = $this->getJson('/api/admissions')->json();
        $this->assertNotContains($closed['id'], array_column($activeListAfter['data'], 'id'));

        $closedList = $this->getJson('/api/admissions/closed')->json();
        $this->assertContains($closed['id'], array_column($closedList['data'], 'id'));
    }
}
