<?php

namespace Tests\Feature;

use App\Models\CID10;
use App\Models\HealthPlan;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientAdmissionTest extends TestCase
{
    use RefreshDatabase;

    private function baseAdmissionPayload(array $overrides = []): array
    {
        CID10::firstOrCreate(['code' => 'G40.9'], [
            'description' => 'Epilepsia, não especificada',
            'category' => 'G40',
            'normalized_description' => 'epilepsia nao especificada',
        ]);

        return array_merge([
            'admission_at' => now()->toDateTimeString(),
            'care_type' => 'INSTITUTIONAL',
            'followup_mode' => 'ONGOING',
            'payer_type' => 'PRIVATE',
            'suspected_cid_code' => 'G40.9',
        ], $overrides);
    }

    public function test_patient_lookup_returns_404_when_not_found(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/patients/lookup?medical_record_number=999999')->assertStatus(404);
    }

    public function test_medical_record_number_is_unique_and_normalized(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/patients', [
            'medical_record_number' => ' 123456 ',
            'full_name' => 'Maria da Silva',
            'date_of_birth' => '1950-01-01',
        ])->assertCreated();

        $this->getJson('/api/patients/lookup?medical_record_number=123456')->assertOk();

        $this->postJson('/api/patients', [
            'medical_record_number' => '123456',
            'full_name' => 'Outra Pessoa',
            'date_of_birth' => '1960-01-01',
        ])->assertStatus(422);
    }

    public function test_cannot_create_second_active_admission_for_same_patient(): void
    {
        $this->actingAs(User::factory()->create());

        $patient = Patient::create([
            'medical_record_number' => '123456',
            'full_name' => 'Maria da Silva',
            'date_of_birth' => '1950-01-01',
        ]);

        $this->postJson('/api/admissions', $this->baseAdmissionPayload(['patient_id' => $patient->id]))
            ->assertCreated();

        $this->postJson('/api/admissions', $this->baseAdmissionPayload(['patient_id' => $patient->id]))
            ->assertStatus(422);
    }

    /**
     * Critical test — seção 108 do PRD: reinternação do mesmo paciente com
     * forma de pagamento diferente nunca deve sobrescrever o episódio anterior.
     */
    public function test_readmission_never_overwrites_previous_episode_health_plan(): void
    {
        $this->actingAs(User::factory()->create());

        $plan = HealthPlan::create(['name' => 'Bradesco Saúde', 'normalized_name' => 'bradesco saude', 'active' => true]);

        $patient = Patient::create([
            'medical_record_number' => '654321',
            'full_name' => 'João Souza',
            'date_of_birth' => '1970-01-01',
        ]);

        $first = $this->postJson('/api/admissions', $this->baseAdmissionPayload([
            'patient_id' => $patient->id,
            'payer_type' => 'HEALTH_PLAN',
            'health_plan_id' => $plan->id,
        ]))->assertCreated()->json();

        $this->postJson("/api/admissions/{$first['id']}/close", [
            'version' => $first['version'],
            'final_cid_code' => 'G40.9',
            'discharge_outcome' => 'Melhora clínica.',
        ])->assertOk();

        $second = $this->postJson('/api/admissions', $this->baseAdmissionPayload([
            'patient_id' => $patient->id,
            'payer_type' => 'PRIVATE',
        ]))->assertCreated()->json();

        $this->assertSame('HEALTH_PLAN', $first['payer_type']);
        $this->assertSame($plan->id, $first['health_plan_id']);
        $this->assertSame('PRIVATE', $second['payer_type']);
        $this->assertNull($second['health_plan_id']);
        $this->assertSame(2, $patient->admissions()->count());

        // O episódio 1 permanece intacto após a criação do episódio 2.
        $reloaded = $this->getJson("/api/admissions/{$first['id']}")->json();
        $this->assertSame('HEALTH_PLAN', $reloaded['payer_type']);
        $this->assertSame($plan->id, $reloaded['health_plan_id']);
    }

    public function test_interconsult_requires_specialty_and_requested_at(): void
    {
        $this->actingAs(User::factory()->create());

        $patient = Patient::create([
            'medical_record_number' => '111222',
            'full_name' => 'Paciente Interconsulta',
            'date_of_birth' => '1980-01-01',
        ]);

        $this->postJson('/api/admissions', $this->baseAdmissionPayload([
            'patient_id' => $patient->id,
            'care_type' => 'INTERCONSULT',
        ]))->assertStatus(422);
    }

    public function test_private_payer_cannot_have_health_plan(): void
    {
        $this->actingAs(User::factory()->create());

        $plan = HealthPlan::create(['name' => 'Amil', 'normalized_name' => 'amil', 'active' => true]);
        $patient = Patient::create([
            'medical_record_number' => '333444',
            'full_name' => 'Paciente Particular',
            'date_of_birth' => '1980-01-01',
        ]);

        $this->postJson('/api/admissions', $this->baseAdmissionPayload([
            'patient_id' => $patient->id,
            'payer_type' => 'PRIVATE',
            'health_plan_id' => $plan->id,
        ]))->assertStatus(422);
    }

    /**
     * Regressão (achado da revisão de segurança independente): restaurar um
     * episódio excluído não revalidava se o paciente já tinha outro episódio
     * ACTIVE — permitindo dois episódios ativos simultâneos para o mesmo
     * paciente (excluir A por engano, criar B, admin restaura A).
     */
    public function test_restore_is_blocked_when_patient_already_has_another_active_admission(): void
    {
        $physician = User::factory()->create();
        $this->actingAs($physician);

        $patient = Patient::create([
            'medical_record_number' => '555666',
            'full_name' => 'Paciente Conflito',
            'date_of_birth' => '1980-01-01',
        ]);

        $first = $this->postJson('/api/admissions', $this->baseAdmissionPayload(['patient_id' => $patient->id]))
            ->assertCreated()->json();

        $this->deleteJson("/api/admissions/{$first['id']}", ['reason' => 'CREATED_BY_MISTAKE'])->assertOk();

        $second = $this->postJson('/api/admissions', $this->baseAdmissionPayload(['patient_id' => $patient->id]))
            ->assertCreated()->json();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->postJson("/api/admissions/{$first['id']}/restore")->assertStatus(422);

        // O segundo episódio continua sendo o único ativo.
        $this->assertSame(1, $patient->admissions()->active()->count());
        $this->assertSame($second['id'], $patient->admissions()->active()->first()->id);
    }

    /**
     * Regressão: editar um episódio enviando só health_plan_id (sem
     * reenviar payer_type junto) deixava health_plan_id apontando pro plano
     * novo mas health_plan_name_snapshot preso no nome do plano antigo —
     * dashboard e exportação usam o snapshot preferencialmente.
     */
    public function test_updating_health_plan_id_alone_recalculates_snapshot(): void
    {
        $this->actingAs(User::factory()->create());

        $planA = HealthPlan::create(['name' => 'Plano A', 'normalized_name' => 'plano a', 'active' => true]);
        $planB = HealthPlan::create(['name' => 'Plano B', 'normalized_name' => 'plano b', 'active' => true]);

        $patient = Patient::create([
            'medical_record_number' => '777888',
            'full_name' => 'Paciente Snapshot',
            'date_of_birth' => '1980-01-01',
        ]);

        $admission = $this->postJson('/api/admissions', $this->baseAdmissionPayload([
            'patient_id' => $patient->id,
            'payer_type' => 'HEALTH_PLAN',
            'health_plan_id' => $planA->id,
        ]))->assertCreated()->json();

        $this->assertSame('Plano A', $admission['health_plan_name_snapshot']);

        // Só health_plan_id, sem payer_type junto.
        $updated = $this->putJson("/api/admissions/{$admission['id']}", [
            'version' => $admission['version'],
            'health_plan_id' => $planB->id,
        ])->assertOk()->json();

        $this->assertSame($planB->id, $updated['health_plan_id']);
        $this->assertSame('Plano B', $updated['health_plan_name_snapshot']);
    }

    /**
     * Regressão: a regra after_or_equal:admission_at em UpdateAdmissionRequest
     * comparava contra um campo inexistente na request (admission_at só
     * existe em StoreAdmissionRequest), virando um no-op silencioso que
     * permitia gravar alta hospitalar anterior à entrada.
     */
    public function test_hospital_discharge_at_cannot_be_before_admission_at_on_update(): void
    {
        $this->actingAs(User::factory()->create());

        $patient = Patient::create([
            'medical_record_number' => '999111',
            'full_name' => 'Paciente Datas',
            'date_of_birth' => '1980-01-01',
        ]);

        $admission = $this->postJson('/api/admissions', $this->baseAdmissionPayload([
            'patient_id' => $patient->id,
            'admission_at' => '2026-08-10 10:00:00',
        ]))->assertCreated()->json();

        $this->putJson("/api/admissions/{$admission['id']}", [
            'version' => $admission['version'],
            'hospital_discharge_at' => '1990-01-01 00:00:00',
        ])->assertStatus(422);
    }
}
