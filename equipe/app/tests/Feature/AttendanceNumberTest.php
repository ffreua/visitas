<?php

namespace Tests\Feature;

use App\Exceptions\ConfirmedMedicalRecordException;
use App\Models\Admission;
use App\Models\CID10;
use App\Models\HealthPlan;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prontuário x número de atendimento: o prontuário identifica a PESSOA
 * (único, imutável depois de gravado, é a chave dos dados de gestão); o
 * número de atendimento identifica a PASSAGEM (muda de uma internação para
 * outra). Os dois precisam funcionar como porta de entrada do cadastro.
 */
class AttendanceNumberTest extends TestCase
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
            'origin' => 'WARD',
            'suspected_cid_code' => 'G40.9',
        ], $overrides);
    }

    public function test_patient_can_be_registered_by_attendance_number_without_medical_record(): void
    {
        $this->actingAs(User::factory()->create());

        $patient = $this->postJson('/api/patients', [
            'attendance_number' => 'AT-1001',
            'full_name' => 'Paciente Sem Prontuário',
            'date_of_birth' => '1975-05-05',
        ])->assertCreated()->json('patient');

        $this->assertNull($patient['medical_record_number']);
        $this->assertNull($patient['medical_record_confirmed_at']);

        $admission = $this->postJson('/api/admissions', $this->baseAdmissionPayload([
            'patient_id' => $patient['id'],
            'attendance_number' => 'at-1001',
        ]))->assertCreated()->json();

        // Normalizado como o prontuário: trim + maiúsculas + sem espaços.
        $this->assertSame('AT-1001', $admission['attendance_number']);
    }

    public function test_registration_requires_medical_record_or_attendance_number(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/patients', [
            'full_name' => 'Paciente Sem Identificador',
            'date_of_birth' => '1975-05-05',
        ])->assertStatus(422)->assertJsonValidationErrors('medical_record_number');
    }

    public function test_medical_record_number_requires_explicit_confirmation(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/patients', [
            'medical_record_number' => '900001',
            'full_name' => 'Paciente Sem Confirmar',
            'date_of_birth' => '1975-05-05',
        ])->assertStatus(422)->assertJsonValidationErrors('medical_record_confirmed');
    }

    public function test_admission_requires_attendance_number_when_patient_has_no_medical_record(): void
    {
        $this->actingAs(User::factory()->create());

        $patient = Patient::create([
            'full_name' => 'Paciente Só Atendimento',
            'date_of_birth' => '1980-01-01',
        ]);

        $this->postJson('/api/admissions', $this->baseAdmissionPayload(['patient_id' => $patient->id]))
            ->assertStatus(422)->assertJsonValidationErrors('attendance_number');
    }

    public function test_lookup_finds_patient_by_attendance_number(): void
    {
        $this->actingAs(User::factory()->create());

        $patient = Patient::create([
            'medical_record_number' => '123456',
            'full_name' => 'Maria da Silva',
            'date_of_birth' => '1950-01-01',
        ]);

        $this->postJson('/api/admissions', $this->baseAdmissionPayload([
            'patient_id' => $patient->id,
            'attendance_number' => 'ATD-77',
        ]))->assertCreated();

        $byAttendance = $this->getJson('/api/patients/lookup?attendance_number=atd-77')->assertOk()->json();

        $this->assertSame($patient->id, $byAttendance['patient']['id']);
        $this->assertSame('ATTENDANCE_NUMBER', $byAttendance['matched_by']);

        $byMrn = $this->getJson('/api/patients/lookup?medical_record_number=123456')->assertOk()->json();
        $this->assertSame('MEDICAL_RECORD_NUMBER', $byMrn['matched_by']);

        $this->getJson('/api/patients/lookup?attendance_number=NAO-EXISTE')->assertStatus(404);
        $this->getJson('/api/patients/lookup')->assertStatus(422);
    }

    /**
     * Dois episódios vivos com o mesmo número de atendimento seriam erro de
     * digitação — e quebrariam a busca por atendimento, que precisa resolver
     * para um único paciente.
     */
    public function test_attendance_number_cannot_repeat_across_live_admissions(): void
    {
        $this->actingAs(User::factory()->create());

        $first = Patient::create(['medical_record_number' => 'A1', 'full_name' => 'Um', 'date_of_birth' => '1980-01-01']);
        $second = Patient::create(['medical_record_number' => 'A2', 'full_name' => 'Dois', 'date_of_birth' => '1980-01-01']);

        $this->postJson('/api/admissions', $this->baseAdmissionPayload([
            'patient_id' => $first->id, 'attendance_number' => 'DUP-1',
        ]))->assertCreated();

        $this->postJson('/api/admissions', $this->baseAdmissionPayload([
            'patient_id' => $second->id, 'attendance_number' => 'DUP-1',
        ]))->assertStatus(422)->assertJsonValidationErrors('attendance_number');
    }

    public function test_attendance_number_and_payer_and_brief_history_are_editable(): void
    {
        $this->actingAs(User::factory()->create());

        $patient = Patient::create(['medical_record_number' => 'B1', 'full_name' => 'Editável', 'date_of_birth' => '1980-01-01']);

        $admission = $this->postJson('/api/admissions', $this->baseAdmissionPayload([
            'patient_id' => $patient->id,
            'attendance_number' => 'OLD-1',
            'brief_history' => 'História inicial.',
        ]))->assertCreated()->json();

        $updated = $this->putJson("/api/admissions/{$admission['id']}", [
            'version' => $admission['version'],
            'attendance_number' => ' new-9 ',
            'payer_type' => 'PRIVATE',
            'brief_history' => 'História corrigida.',
        ])->assertOk()->json();

        $this->assertSame('NEW-9', $updated['attendance_number']);
        $this->assertSame('História corrigida.', $updated['brief_history']);
    }

    /**
     * Regressão: trocar convênio → particular limpava só o snapshot do
     * plano, deixando health_plan_id apontando para o plano antigo — o
     * episódio ficava "particular" com um convênio pendurado.
     */
    public function test_switching_to_private_clears_the_health_plan_link(): void
    {
        $this->actingAs(User::factory()->create());

        $plan = HealthPlan::create(['name' => 'Amil', 'normalized_name' => 'amil', 'active' => true]);
        $patient = Patient::create(['medical_record_number' => 'F1', 'full_name' => 'Troca Pagador', 'date_of_birth' => '1980-01-01']);

        $admission = $this->postJson('/api/admissions', $this->baseAdmissionPayload([
            'patient_id' => $patient->id,
            'payer_type' => 'HEALTH_PLAN',
            'health_plan_id' => $plan->id,
        ]))->assertCreated()->json();

        $updated = $this->putJson("/api/admissions/{$admission['id']}", [
            'version' => $admission['version'],
            'payer_type' => 'PRIVATE',
        ])->assertOk()->json();

        $this->assertSame('PRIVATE', $updated['payer_type']);
        $this->assertNull($updated['health_plan_id']);
        $this->assertNull($updated['health_plan_name_snapshot']);
    }

    /**
     * Entrada (internação) e CID inicial são os únicos dados travados no
     * episódio — mandá-los na edição não pode alterar nada.
     */
    public function test_admission_at_and_initial_cid_are_not_editable(): void
    {
        $this->actingAs(User::factory()->create());

        CID10::firstOrCreate(['code' => 'R55'], [
            'description' => 'Síncope', 'category' => 'R55', 'normalized_description' => 'sincope',
        ]);

        $patient = Patient::create(['medical_record_number' => 'C1', 'full_name' => 'Travado', 'date_of_birth' => '1980-01-01']);

        $admission = $this->postJson('/api/admissions', $this->baseAdmissionPayload([
            'patient_id' => $patient->id,
            'admission_at' => '2026-08-10 08:00:00',
            'attendance_number' => 'LOCK-1',
        ]))->assertCreated()->json();

        $updated = $this->putJson("/api/admissions/{$admission['id']}", [
            'version' => $admission['version'],
            'admission_at' => '2020-01-01 00:00:00',
            'suspected_cid_code' => 'R55',
        ])->assertOk()->json();

        $this->assertSame($admission['admission_at'], $updated['admission_at']);

        $suspected = collect($updated['diagnoses'])->where('phase', 'SUSPECTED')->values();
        $this->assertCount(1, $suspected);
        $this->assertSame('G40.9', $suspected[0]['cid_code']);
    }

    public function test_search_matches_attendance_number(): void
    {
        $this->actingAs(User::factory()->create());

        $patient = Patient::create(['medical_record_number' => 'D1', 'full_name' => 'Buscável', 'date_of_birth' => '1980-01-01']);

        $this->postJson('/api/admissions', $this->baseAdmissionPayload([
            'patient_id' => $patient->id, 'attendance_number' => 'FIND-42',
        ]))->assertCreated();

        $other = Patient::create(['medical_record_number' => 'D2', 'full_name' => 'Outro', 'date_of_birth' => '1980-01-01']);
        $this->postJson('/api/admissions', $this->baseAdmissionPayload([
            'patient_id' => $other->id, 'attendance_number' => 'OTHER-1',
        ]))->assertCreated();

        $results = $this->getJson('/api/admissions?search=find-42')->assertOk()->json('data');

        $this->assertCount(1, $results);
        $this->assertSame('FIND-42', $results[0]['attendance_number']);
    }

    public function test_patient_name_and_birth_date_are_editable(): void
    {
        $this->actingAs(User::factory()->create());

        $patient = Patient::create(['medical_record_number' => 'E1', 'full_name' => 'Nome Errado', 'date_of_birth' => '1980-01-01']);

        $updated = $this->putJson("/api/patients/{$patient->id}", [
            'full_name' => 'Nome Correto',
            'date_of_birth' => '1981-02-03',
        ])->assertOk()->json('patient');

        $this->assertSame('Nome Correto', $updated['full_name']);
        $this->assertStringStartsWith('1981-02-03', $updated['date_of_birth']);
    }

    public function test_pending_medical_record_can_be_filled_once_and_then_locked(): void
    {
        $this->actingAs(User::factory()->create());

        $patient = Patient::create(['full_name' => 'Sem Prontuário', 'date_of_birth' => '1980-01-01']);

        $filled = $this->putJson("/api/patients/{$patient->id}", [
            'medical_record_number' => ' pr-555 ',
            'medical_record_confirmed' => true,
        ])->assertOk()->json('patient');

        $this->assertSame('PR-555', $filled['medical_record_number']);
        $this->assertNotNull($filled['medical_record_confirmed_at']);

        $this->putJson("/api/patients/{$patient->id}", [
            'medical_record_number' => 'PR-999',
            'medical_record_confirmed' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('medical_record_number');

        $this->assertSame('PR-555', $patient->fresh()->medical_record_number);
    }

    public function test_filling_medical_record_requires_confirmation(): void
    {
        $this->actingAs(User::factory()->create());

        $patient = Patient::create(['full_name' => 'Sem Prontuário 2', 'date_of_birth' => '1980-01-01']);

        $this->putJson("/api/patients/{$patient->id}", ['medical_record_number' => 'PR-777'])
            ->assertStatus(422)->assertJsonValidationErrors('medical_record_confirmed');

        $this->assertNull($patient->fresh()->medical_record_number);
    }

    public function test_duplicate_medical_record_number_is_rejected_on_update(): void
    {
        $this->actingAs(User::factory()->create());

        Patient::create(['medical_record_number' => 'TAKEN', 'full_name' => 'Já Existe', 'date_of_birth' => '1980-01-01']);
        $patient = Patient::create(['full_name' => 'Pendente', 'date_of_birth' => '1980-01-01']);

        $this->putJson("/api/patients/{$patient->id}", [
            'medical_record_number' => 'taken',
            'medical_record_confirmed' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('medical_record_number');
    }

    /**
     * A regra não pode depender só da validação da request: qualquer
     * caminho de escrita (comando, seeder, import futuro) tem que bater no
     * mesmo bloqueio.
     */
    public function test_model_itself_blocks_changing_a_stored_medical_record_number(): void
    {
        $patient = Patient::create([
            'medical_record_number' => 'IMUTAVEL',
            'full_name' => 'Guardado',
            'date_of_birth' => '1980-01-01',
        ]);

        $this->expectException(ConfirmedMedicalRecordException::class);

        $patient->update(['medical_record_number' => 'OUTRO']);
    }

    /**
     * Procedência é vocabulário fechado: texto livre aqui inviabilizaria
     * comparar séries no tempo ("PS", "P.S.", "pronto socorro" virariam
     * três categorias). Obrigatória no cadastro para que a série nova
     * nasça completa.
     */
    public function test_origin_is_required_and_limited_to_the_fixed_vocabulary(): void
    {
        $this->actingAs(User::factory()->create());

        $patient = Patient::create(['medical_record_number' => 'G1', 'full_name' => 'Procedência', 'date_of_birth' => '1980-01-01']);

        $semProcedencia = $this->baseAdmissionPayload(['patient_id' => $patient->id]);
        unset($semProcedencia['origin']);
        $this->postJson('/api/admissions', $semProcedencia)
            ->assertStatus(422)->assertJsonValidationErrors('origin');

        $this->postJson('/api/admissions', $this->baseAdmissionPayload([
            'patient_id' => $patient->id,
            'origin' => 'Pronto Socorro',
        ]))->assertStatus(422)->assertJsonValidationErrors('origin');

        $admission = $this->postJson('/api/admissions', $this->baseAdmissionPayload([
            'patient_id' => $patient->id,
            'origin' => 'EMERGENCY_ROOM',
        ]))->assertCreated()->json();

        $this->assertSame('EMERGENCY_ROOM', $admission['origin']);
    }

    public function test_origin_can_be_corrected_but_never_to_a_free_text_value(): void
    {
        $this->actingAs(User::factory()->create());

        $patient = Patient::create(['medical_record_number' => 'G2', 'full_name' => 'Corrige', 'date_of_birth' => '1980-01-01']);

        $admission = $this->postJson('/api/admissions', $this->baseAdmissionPayload([
            'patient_id' => $patient->id,
            'origin' => 'WARD',
        ]))->assertCreated()->json();

        $atualizado = $this->putJson("/api/admissions/{$admission['id']}", [
            'version' => $admission['version'],
            'origin' => 'ICU',
        ])->assertOk()->json();

        $this->assertSame('ICU', $atualizado['origin']);

        $this->putJson("/api/admissions/{$admission['id']}", [
            'version' => $atualizado['version'],
            'origin' => 'UTI mesmo',
        ])->assertStatus(422)->assertJsonValidationErrors('origin');
    }

    public function test_admission_keeps_working_without_attendance_number_for_legacy_patients(): void
    {
        $this->actingAs(User::factory()->create());

        $patient = Patient::create(['medical_record_number' => 'LEGADO', 'full_name' => 'Legado', 'date_of_birth' => '1980-01-01']);

        $admission = $this->postJson('/api/admissions', $this->baseAdmissionPayload(['patient_id' => $patient->id]))
            ->assertCreated()->json();

        $this->assertNull($admission['attendance_number']);
        $this->assertNull(Admission::find($admission['id'])->attendance_number);
    }
}
