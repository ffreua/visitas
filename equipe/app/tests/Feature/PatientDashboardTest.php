<?php

namespace Tests\Feature;

use App\Models\CID10;
use App\Models\HealthPlan;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dashboard de gestão por prontuário: a trajetória de um paciente e o
 * detalhe de cada atendimento dele.
 */
class PatientDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedCids(): void
    {
        CID10::firstOrCreate(['code' => 'G40.9'], [
            'description' => 'Epilepsia', 'category' => 'G40', 'normalized_description' => 'epilepsia',
        ]);
        CID10::firstOrCreate(['code' => 'R55'], [
            'description' => 'Síncope', 'category' => 'R55', 'normalized_description' => 'sincope',
        ]);
    }

    public function test_physician_cannot_access_patient_dashboard(): void
    {
        $this->actingAs(User::factory()->create());

        $patient = Patient::create(['medical_record_number' => 'X1', 'full_name' => 'Qualquer', 'date_of_birth' => '1980-01-01']);

        $this->getJson('/api/admin/dashboard/patients')->assertForbidden();
        $this->getJson("/api/admin/dashboard/patients/{$patient->id}")->assertForbidden();
    }

    public function test_dashboard_lists_every_admission_of_the_medical_record_with_details(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $this->seedCids();

        $plan = HealthPlan::create(['name' => 'Bradesco', 'normalized_name' => 'bradesco', 'active' => true]);
        $patient = Patient::create(['medical_record_number' => 'PRT-1', 'full_name' => 'Maria Trajetória', 'date_of_birth' => '1960-01-01']);

        // Internação 1: plano de saúde, encerrada.
        Carbon::setTestNow('2026-08-01 10:00:00');
        $first = $this->postJson('/api/admissions', [
            'patient_id' => $patient->id, 'attendance_number' => 'ATD-1',
            'admission_at' => now()->toDateTimeString(),
            'care_type' => 'INSTITUTIONAL', 'followup_mode' => 'ONGOING',
            'payer_type' => 'HEALTH_PLAN', 'health_plan_id' => $plan->id,
            'origin' => 'WARD',
            'suspected_cid_code' => 'G40.9',
        ])->assertCreated()->json();

        $this->postJson("/api/admissions/{$first['id']}/rounds/assign", ['assigned_physician_id' => $admin->id]);
        $this->postJson("/api/admissions/{$first['id']}/rounds/complete");
        $this->postJson("/api/admissions/{$first['id']}/pending-items", ['description' => 'Solicitar EEG']);

        Carbon::setTestNow('2026-08-05 10:00:00');
        $this->postJson("/api/admissions/{$first['id']}/close", [
            'version' => $this->getJson("/api/admissions/{$first['id']}")->json('version'),
            'final_cid_code' => 'R55',
            'health_plan_confirmed' => true,
            'discharge_outcome' => 'Melhora clínica.',
        ])->assertOk();

        // Internação 2 (reinternação): particular, ainda ativa.
        Carbon::setTestNow('2026-08-12 10:00:00');
        $second = $this->postJson('/api/admissions', [
            'patient_id' => $patient->id, 'attendance_number' => 'ATD-2',
            'admission_at' => now()->toDateTimeString(),
            'care_type' => 'INSTITUTIONAL', 'followup_mode' => 'ONGOING',
            'payer_type' => 'PRIVATE',
            'origin' => 'WARD',
            'suspected_cid_code' => 'G40.9',
        ])->assertCreated()->json();

        $data = $this->getJson("/api/admin/dashboard/patients/{$patient->id}")->assertOk()->json();

        $this->assertSame('PRT-1', $data['patient']['medical_record_number']);
        $this->assertTrue($data['patient']['medical_record_confirmed']);

        $this->assertSame(2, $data['summary']['episodes_total']);
        $this->assertSame(1, $data['summary']['episodes_active']);
        $this->assertSame(1, $data['summary']['episodes_closed']);
        $this->assertSame(1, $data['summary']['payers']['PRIVATE']);
        $this->assertSame(1, $data['summary']['payers']['HEALTH_PLAN']);
        $this->assertSame(1, $data['summary']['pending_open']);

        // Reinternação: 7 dias entre o encerramento do episódio 1 e a
        // entrada do episódio 2.
        $this->assertCount(1, $data['summary']['readmission_gaps_days']);
        // assertEquals, não assertSame: 7.0 é serializado como 7 no JSON.
        $this->assertEquals(7, $data['summary']['readmission_gaps_days'][0]['gap_days']);

        // Episódios em ordem decrescente de entrada, com detalhe de cada um.
        $this->assertSame([$second['id'], $first['id']], array_column($data['episodes'], 'id'));
        $this->assertSame(['ATD-2', 'ATD-1'], array_column($data['episodes'], 'attendance_number'));

        $closed = $data['episodes'][1];
        $this->assertSame('CLOSED', $closed['status']);
        $this->assertSame('Bradesco', $closed['health_plan']);
        $this->assertSame('G40.9', $closed['diagnoses']['suspected'][0]['cid_code']);
        $this->assertSame('R55', $closed['diagnoses']['final'][0]['cid_code']);
        $this->assertSame(1, $closed['rounds']['completed']);
        $this->assertSame(1, $closed['pending_items']['open']);
        $this->assertEquals(4, $closed['neurology_days']);
    }

    public function test_search_finds_patient_by_medical_record_and_by_attendance_number(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $this->seedCids();

        $patient = Patient::create(['medical_record_number' => 'BUSCA-9', 'full_name' => 'Alvo da Busca', 'date_of_birth' => '1960-01-01']);
        Patient::create(['medical_record_number' => 'OUTRO-9', 'full_name' => 'Não Deve Aparecer', 'date_of_birth' => '1960-01-01']);

        $this->postJson('/api/admissions', [
            'patient_id' => $patient->id, 'attendance_number' => 'ATN-500',
            'admission_at' => now()->toDateTimeString(),
            'care_type' => 'INSTITUTIONAL', 'followup_mode' => 'ONGOING', 'payer_type' => 'PRIVATE',
            'origin' => 'WARD',
            'suspected_cid_code' => 'G40.9',
        ])->assertCreated();

        $byMrn = $this->getJson('/api/admin/dashboard/patients?search=busca-9')->assertOk()->json('patients');
        $this->assertCount(1, $byMrn);
        $this->assertSame($patient->id, $byMrn[0]['id']);
        $this->assertSame(['ATN-500'], $byMrn[0]['attendance_numbers']);

        $byAttendance = $this->getJson('/api/admin/dashboard/patients?search=atn-500')->assertOk()->json('patients');
        $this->assertCount(1, $byAttendance);
        $this->assertSame($patient->id, $byAttendance[0]['id']);

        $byName = $this->getJson('/api/admin/dashboard/patients?search=Alvo')->assertOk()->json('patients');
        $this->assertCount(1, $byName);
    }

    public function test_deleted_episodes_appear_only_when_explicitly_requested(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $this->seedCids();

        $patient = Patient::create(['medical_record_number' => 'DEL-1', 'full_name' => 'Com Excluído', 'date_of_birth' => '1960-01-01']);

        $admission = $this->postJson('/api/admissions', [
            'patient_id' => $patient->id, 'attendance_number' => 'ATD-DEL',
            'admission_at' => now()->toDateTimeString(),
            'care_type' => 'INSTITUTIONAL', 'followup_mode' => 'ONGOING', 'payer_type' => 'PRIVATE',
            'origin' => 'WARD',
            'suspected_cid_code' => 'G40.9',
        ])->assertCreated()->json();

        $this->deleteJson("/api/admissions/{$admission['id']}", ['reason' => 'CREATED_BY_MISTAKE'])->assertOk();

        $default = $this->getJson("/api/admin/dashboard/patients/{$patient->id}")->assertOk()->json();
        $this->assertSame(0, $default['summary']['episodes_total']);

        $withDeleted = $this->getJson("/api/admin/dashboard/patients/{$patient->id}?include_deleted=1")->assertOk()->json();
        $this->assertSame(1, $withDeleted['summary']['episodes_total']);
        $this->assertSame(1, $withDeleted['summary']['episodes_deleted']);
        $this->assertNotNull($withDeleted['episodes'][0]['deleted_at']);
        $this->assertSame('Criado por engano', $withDeleted['episodes'][0]['deletion_reason']);
    }
}
