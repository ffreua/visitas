<?php

namespace Tests\Feature;

use App\Models\CID10;
use App\Models\HealthPlan;
use App\Models\Patient;
use App\Models\User;
use App\Services\AmhsBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MyRoundsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sequencial dos identificadores gerados. Propriedade de INSTANCIA, nao
     * static: o PHPUnit cria um objeto por teste, entao cada teste comeca do
     * 1 -- com static, o contador vazava entre os metodos e as asserts que
     * citam "MRN1" quebravam com --order-by=random.
     */
    private int $seq = 0;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeAdmission(array $overrides = []): array
    {
        $seq = ++$this->seq;

        CID10::firstOrCreate(
            ['code' => 'G40.9'],
            ['description' => 'Epilepsia', 'category' => 'G40', 'normalized_description' => 'epilepsia']
        );

        $patient = Patient::create([
            'medical_record_number' => 'MRN'.$seq,
            'full_name' => $overrides['patient_name'] ?? 'Paciente '.$seq,
            'date_of_birth' => '1970-05-04',
        ]);

        $payload = array_merge([
            'patient_id' => $patient->id,
            'attendance_number' => 'AT'.$seq,
            'admission_at' => now()->toDateTimeString(),
            'care_type' => 'INSTITUTIONAL',
            'followup_mode' => 'ONGOING',
            'payer_type' => 'PRIVATE',
            'origin' => 'WARD',
            'suspected_cid_code' => 'G40.9',
        ], $overrides['admission'] ?? []);

        return $this->postJson('/api/admissions', $payload)->assertCreated()->json();
    }

    public function test_lists_only_rounds_signed_by_the_authenticated_physician(): void
    {
        Carbon::setTestNow('2026-09-10 09:00:00');

        $mine = User::factory()->create(['full_name' => 'Dra. Ana', 'crm' => '11111']);
        $other = User::factory()->create(['full_name' => 'Dr. Bruno']);

        $this->actingAs($mine);
        $meu = $this->makeAdmission(['patient_name' => 'Paciente Meu']);
        $this->postJson("/api/admissions/{$meu['id']}/rounds/complete")->assertOk();

        $this->actingAs($other);
        $dele = $this->makeAdmission(['patient_name' => 'Paciente Dele']);
        $this->postJson("/api/admissions/{$dele['id']}/rounds/complete")->assertOk();

        $this->actingAs($mine);
        $body = $this->getJson('/api/my-rounds')->assertOk()->json();

        $this->assertSame('Dra. Ana', $body['physician']['full_name']);
        $this->assertCount(1, $body['rows']);
        $this->assertSame('Paciente Meu', $body['rows'][0]['patient_name']);
        $this->assertSame('2026-09-10', $body['rows'][0]['round_date']);
        $this->assertSame('G40.9', $body['rows'][0]['cid_code']);
        $this->assertSame('MRN1', $body['rows'][0]['medical_record_number']);
        $this->assertSame('AT1', $body['rows'][0]['attendance_number']);
    }

    /**
     * Visita ATRIBUÍDA e não assinada não foi feita — não pode entrar numa
     * folha que serve de base para cobrança.
     */
    public function test_assigned_but_unsigned_round_is_not_listed(): void
    {
        Carbon::setTestNow('2026-09-10 09:00:00');

        $physician = User::factory()->create();
        $this->actingAs($physician);

        $admission = $this->makeAdmission();
        $this->postJson("/api/admissions/{$admission['id']}/rounds/assign", [
            'assigned_physician_id' => $physician->id,
        ])->assertOk();

        $this->assertCount(0, $this->getJson('/api/my-rounds')->assertOk()->json('rows'));
    }

    public function test_defaults_to_current_month_and_honours_explicit_range(): void
    {
        $physician = User::factory()->create();
        $this->actingAs($physician);

        Carbon::setTestNow('2026-08-20 09:00:00');
        $agosto = $this->makeAdmission(['patient_name' => 'Paciente Agosto']);
        $this->postJson("/api/admissions/{$agosto['id']}/rounds/complete")->assertOk();

        Carbon::setTestNow('2026-09-03 09:00:00');
        $setembro = $this->makeAdmission(['patient_name' => 'Paciente Setembro']);
        $this->postJson("/api/admissions/{$setembro['id']}/rounds/complete")->assertOk();

        $padrao = $this->getJson('/api/my-rounds')->assertOk()->json();
        $this->assertSame('2026-09-01', $padrao['date_from']);
        $this->assertSame(['Paciente Setembro'], array_column($padrao['rows'], 'patient_name'));

        $amplo = $this->getJson('/api/my-rounds?date_from=2026-08-01&date_to=2026-09-30')->assertOk()->json();
        $this->assertSame(
            ['Paciente Agosto', 'Paciente Setembro'],
            array_column($amplo['rows'], 'patient_name')
        );

        $this->getJson('/api/my-rounds?date_from=2026-09-30&date_to=2026-09-01')->assertStatus(422);
    }

    public function test_excludes_rounds_of_deleted_admissions(): void
    {
        Carbon::setTestNow('2026-09-10 09:00:00');

        $physician = User::factory()->create();
        $this->actingAs($physician);

        $admission = $this->makeAdmission();
        $this->postJson("/api/admissions/{$admission['id']}/rounds/complete")->assertOk();
        $this->assertCount(1, $this->getJson('/api/my-rounds')->json('rows'));

        $this->deleteJson("/api/admissions/{$admission['id']}", ['reason' => 'CREATED_BY_MISTAKE'])->assertOk();

        $this->assertCount(0, $this->getJson('/api/my-rounds')->json('rows'));
    }

    public function test_flags_amhs_payers_and_leaves_the_others_alone(): void
    {
        Carbon::setTestNow('2026-09-10 09:00:00');

        $physician = User::factory()->create();
        $this->actingAs($physician);

        $cobrados = ['Allianz (AGF)', 'Bradesco Saúde', 'Cassi', 'Saúde Caixa', 'Unafisco/Sindfisco', 'Mediservice'];
        $naoCobrados = ['SulAmérica', 'Amil'];

        foreach ([...$cobrados, ...$naoCobrados] as $nome) {
            $plano = HealthPlan::create(['name' => $nome, 'active' => true]);
            $admission = $this->makeAdmission(['admission' => [
                'payer_type' => 'HEALTH_PLAN',
                'health_plan_id' => $plano->id,
            ]]);
            $this->postJson("/api/admissions/{$admission['id']}/rounds/complete")->assertOk();
        }

        // Particular entra na lista da AMHS e não é convênio: é o payer_type.
        $particular = $this->makeAdmission();
        $this->postJson("/api/admissions/{$particular['id']}/rounds/complete")->assertOk();

        $body = $this->getJson('/api/my-rounds')->assertOk()->json();
        $this->assertSame(AmhsBilling::LABEL, $body['amhs_label']);

        $porPagador = collect($body['rows'])->keyBy('payer');

        foreach ([...$cobrados, 'Particular'] as $nome) {
            $this->assertTrue($porPagador[$nome]['amhs'], "{$nome} deveria ser cobrado via AMHS.");
        }

        foreach ($naoCobrados as $nome) {
            $this->assertFalse($porPagador[$nome]['amhs'], "{$nome} NAO deveria ser cobrado via AMHS.");
        }
    }
}
