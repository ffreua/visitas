<?php

namespace Tests\Feature;

use App\Models\CID10;
use App\Models\HealthPlan;
use App\Models\MedicalSpecialty;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_physician_cannot_access_dashboard(): void
    {
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/admin/dashboard')->assertForbidden();
    }

    public function test_dashboard_computes_expected_volume_and_payer_breakdown(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        CID10::create(['code' => 'G40.9', 'description' => 'Epilepsia', 'category' => 'G40', 'normalized_description' => 'epilepsia']);
        CID10::create(['code' => 'R55', 'description' => 'Síncope', 'category' => 'R55', 'normalized_description' => 'sincope']);
        $plan = HealthPlan::create(['name' => 'Bradesco', 'normalized_name' => 'bradesco', 'active' => true]);
        $specialty = MedicalSpecialty::create(['name' => 'Cardiologia', 'normalized_name' => 'cardiologia', 'active' => true]);

        // Episódio 1: particular, institucional, ainda ativo.
        Carbon::setTestNow('2026-08-01 10:00:00');
        $p1 = Patient::create(['medical_record_number' => '1', 'full_name' => 'Paciente 1', 'date_of_birth' => '1980-01-01']);
        $admission1 = $this->postJson('/api/admissions', [
            'patient_id' => $p1->id, 'admission_at' => now()->toDateTimeString(),
            'care_type' => 'INSTITUTIONAL', 'followup_mode' => 'ONGOING', 'payer_type' => 'PRIVATE',
            'suspected_cid_code' => 'G40.9',
        ])->json();

        // Episódio 2: plano de saúde, interconsulta, avaliação única, concluída no mesmo dia.
        $p2 = Patient::create(['medical_record_number' => '2', 'full_name' => 'Paciente 2', 'date_of_birth' => '1975-01-01']);
        $admission2 = $this->postJson('/api/admissions', [
            'patient_id' => $p2->id, 'admission_at' => now()->toDateTimeString(),
            'care_type' => 'INTERCONSULT', 'requesting_specialty_id' => $specialty->id,
            'consult_requested_at' => now()->toDateTimeString(),
            'followup_mode' => 'SINGLE_EVALUATION', 'payer_type' => 'HEALTH_PLAN', 'health_plan_id' => $plan->id,
            'suspected_cid_code' => 'G40.9',
        ])->json();

        $this->postJson("/api/admissions/{$admission2['id']}/rounds/assign", ['assigned_physician_id' => $admin->id]);
        $this->postJson("/api/admissions/{$admission2['id']}/rounds/complete");
        $this->postJson("/api/admissions/{$admission2['id']}/close", [
            'version' => $admission2['version'], 'final_cid_code' => 'G40.9', 'discharge_outcome' => 'Concordante.',
        ]);

        // Pendência aberta e resolvida no episódio 1.
        $pending = $this->postJson("/api/admissions/{$admission1['id']}/pending-items", ['description' => 'Aguardar exame'])->json();
        $this->postJson("/api/pending-items/{$pending['id']}/resolve", ['status' => 'DONE']);

        $this->postJson("/api/admissions/{$admission1['id']}/rounds/assign", ['assigned_physician_id' => $admin->id]);
        $this->postJson("/api/admissions/{$admission1['id']}/rounds/complete");

        Carbon::setTestNow('2026-08-05 10:00:00');

        $data = $this->getJson('/api/admin/dashboard')->assertOk()->json();

        $this->assertSame(2, $data['volume']['episodes']);
        $this->assertSame(2, $data['volume']['unique_patients']);
        $this->assertSame(1, $data['volume']['currently_active']);
        $this->assertSame(1, $data['volume']['discharges']);
        $this->assertSame(1, $data['volume']['new_interconsults']);
        $this->assertSame(1, $data['volume']['single_evaluations']);

        $this->assertSame(1, $data['payers']['private_vs_plan']['PRIVATE']);
        $this->assertSame(1, $data['payers']['private_vs_plan']['HEALTH_PLAN']);

        $this->assertSame(1, $data['interconsults']['count']);

        $this->assertSame(1, $data['pending_items']['created']);
        $this->assertSame(1, $data['pending_items']['resolved']);
        $this->assertSame(0, $data['pending_items']['open']);

        $this->assertSame(1, $data['single_evaluations']['count']);
        $this->assertEquals(100.0, $data['single_evaluations']['same_day_pct']);

        // Cobertura de visita considera só episódios ainda ATIVOS (admission2 já foi encerrado) —
        // só o round do admission1 (ainda ativo) entra na contagem.
        $this->assertSame(1, $data['visit_coverage']['visited_patient_days']);
        $this->assertSame(1, $data['visit_coverage']['active_patient_days']);
    }

    public function test_data_quality_panel_flags_active_admission_without_diagnosis(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        CID10::create(['code' => 'G40.9', 'description' => 'Epilepsia', 'category' => 'G40', 'normalized_description' => 'epilepsia']);
        $patient = Patient::create(['medical_record_number' => '10', 'full_name' => 'Paciente Qualidade', 'date_of_birth' => '1980-01-01']);

        $this->postJson('/api/admissions', [
            'patient_id' => $patient->id, 'admission_at' => now()->toDateTimeString(),
            'care_type' => 'INSTITUTIONAL', 'followup_mode' => 'ONGOING', 'payer_type' => 'PRIVATE',
            'suspected_cid_code' => 'G40.9',
        ])->assertCreated();

        $data = $this->getJson('/api/admin/dashboard/data-quality')->assertOk()->json();

        $this->assertSame(1, $data['without_responsible_today']);
        $this->assertSame(1, $data['not_visited_today']);
        $this->assertSame(0, $data['active_without_suspected_diagnosis']);
    }
}
