<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AdmissionDiagnosis;
use App\Models\CID10;
use App\Models\DailyRound;
use App\Models\HealthPlan;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Cada teste aqui reproduz um cenário em que o dashboard devolvia um número
 * errado, e trava o número certo. São os casos que a auditoria encontrou —
 * não estão aqui para exercitar código, estão para impedir a volta do erro.
 */
class DashboardAccuracyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function patient(string $name): Patient
    {
        return Patient::create([
            'full_name' => $name,
            'medical_record_number' => strtoupper(substr(md5($name), 0, 8)),
            'date_of_birth' => '1950-01-01',
        ]);
    }

    private function episode(Patient $patient, array $attributes): Admission
    {
        return Admission::create(array_merge([
            'patient_id' => $patient->id,
            'care_type' => 'INSTITUTIONAL',
            'followup_mode' => 'ONGOING',
            'payer_type' => 'PRIVATE',
            'created_by' => User::factory()->create()->id,
        ], $attributes));
    }

    private function visit(Admission $admission, string $date, User $physician): DailyRound
    {
        return DailyRound::create([
            'admission_id' => $admission->id,
            'round_date' => $date,
            'assigned_physician_id' => $physician->id,
            'assigned_by' => $physician->id,
            'completed_by' => $physician->id,
            'completed_at' => $date.' 09:00:00',
        ]);
    }

    private function dashboard(string $query = ''): array
    {
        return $this->getJson('/api/admin/dashboard'.$query)->assertOk()->json();
    }

    /**
     * O erro mais grave: o denominador contava LINHAS de daily_rounds, que só
     * nascem quando alguém age. Dia sem ninguém tocar no paciente sumia do
     * denominador, e a cobertura dava 100% justamente quando a equipe tinha
     * visitado pouco.
     */
    public function test_coverage_counts_every_day_under_followup_not_only_the_days_someone_acted(): void
    {
        Carbon::setTestNow('2026-08-31 10:00:00');
        $med = User::factory()->create();

        // 30 dias sob acompanhamento (01/08 a 31/08 inclusive = 31 dias), 2 visitas.
        $a = $this->episode($this->patient('Longa'), [
            'admission_at' => '2026-08-01 08:00',
            'neurology_followup_started_at' => '2026-08-01 08:00',
            'status' => 'ACTIVE',
        ]);
        $this->visit($a, '2026-08-02', $med);
        $this->visit($a, '2026-08-20', $med);

        $this->actingAs(User::factory()->admin()->create());
        $coverage = $this->dashboard('?date_from=2026-08-01&date_to=2026-08-31')['visit_coverage'];

        $this->assertSame(31, $coverage['expected_patient_days']);
        $this->assertSame(2, $coverage['visited_patient_days']);
        $this->assertEqualsWithDelta(6.5, $coverage['coverage_pct'], 0.1);
    }

    /** Num relatório de mês fechado, todo episódio já encerrou — e o indicador vinha em branco. */
    public function test_coverage_still_reports_for_a_period_where_every_episode_is_already_closed(): void
    {
        Carbon::setTestNow('2026-09-30 10:00:00');
        $med = User::factory()->create();

        $a = $this->episode($this->patient('Encerrado'), [
            'admission_at' => '2026-08-10 08:00',
            'neurology_followup_started_at' => '2026-08-10 08:00',
            'neurology_followup_closed_at' => '2026-08-14 08:00',
            'status' => 'CLOSED',
        ]);
        $this->visit($a, '2026-08-11', $med);
        $this->visit($a, '2026-08-12', $med);

        $this->actingAs(User::factory()->admin()->create());
        $coverage = $this->dashboard('?date_from=2026-08-01&date_to=2026-08-31')['visit_coverage'];

        // 10/08 a 14/08 = 5 dias de oportunidade, 2 visitados.
        $this->assertSame(5, $coverage['expected_patient_days']);
        $this->assertSame(2, $coverage['visited_patient_days']);
        $this->assertEqualsWithDelta(40.0, $coverage['coverage_pct'], 0.1);
    }

    /**
     * O recorte era por data de ENTRADA: quem entrou em julho e seguiu
     * internado agosto inteiro não aparecia no relatório de agosto — nem ele,
     * nem os patient-days, nem as visitas que a equipe fez nele.
     */
    public function test_long_stay_patient_admitted_before_the_window_still_counts_in_the_window(): void
    {
        Carbon::setTestNow('2026-08-31 10:00:00');
        $med = User::factory()->create();

        $a = $this->episode($this->patient('Veio de julho'), [
            'admission_at' => '2026-07-20 08:00',
            'neurology_followup_started_at' => '2026-07-20 08:00',
            'status' => 'ACTIVE',
        ]);
        $this->visit($a, '2026-08-05', $med);

        $this->actingAs(User::factory()->admin()->create());
        $data = $this->dashboard('?date_from=2026-08-01&date_to=2026-08-31');

        $this->assertSame(1, $data['volume']['episodes']);
        $this->assertSame(1, $data['volume']['unique_patients']);
        // Entrou em julho: conta como carga assistencial de agosto, mas NÃO
        // como entrada nova de agosto.
        $this->assertSame(0, $data['volume']['new_admissions']);
        // Patient-days recortados na janela: 31 dias de agosto, não os 42 do
        // episódio inteiro. Mesma unidade do denominador da cobertura.
        $this->assertSame(31, $data['volume']['neurology_patient_days']);
        $this->assertSame(31, $data['visit_coverage']['expected_patient_days']);

        // E o modo "entradas no período" continua disponível, com o recorte antigo.
        $porEntrada = $this->dashboard('?date_from=2026-08-01&date_to=2026-08-31&period_mode=ADMISSION');
        $this->assertSame(0, $porEntrada['volume']['episodes']);
    }

    /**
     * "Altas" eram os episódios ADMITIDOS na janela que hoje estão encerrados
     * — não os encerramentos ocorridos na janela. O número saía errado e
     * composto por outros episódios.
     */
    public function test_closures_are_counted_by_the_date_they_happened(): void
    {
        Carbon::setTestNow('2026-09-30 10:00:00');

        // A: entrou em julho, Neurologia encerrou em 05/08 e alta hospitalar em 06/08.
        $this->episode($this->patient('A'), [
            'admission_at' => '2026-07-20 08:00',
            'neurology_followup_started_at' => '2026-07-20 08:00',
            'neurology_followup_closed_at' => '2026-08-05 10:00',
            'hospital_discharge_at' => '2026-08-06 12:00',
            'status' => 'CLOSED',
        ]);
        // B: entrou em 25/08 mas só encerrou em setembro.
        $this->episode($this->patient('B'), [
            'admission_at' => '2026-08-25 08:00',
            'neurology_followup_started_at' => '2026-08-25 08:00',
            'neurology_followup_closed_at' => '2026-09-10 10:00',
            'hospital_discharge_at' => '2026-09-11 12:00',
            'status' => 'CLOSED',
        ]);
        // C: entrou e encerrou dentro de agosto, sem alta hospitalar registrada.
        $this->episode($this->patient('C'), [
            'admission_at' => '2026-08-02 08:00',
            'neurology_followup_started_at' => '2026-08-02 08:00',
            'neurology_followup_closed_at' => '2026-08-15 10:00',
            'status' => 'CLOSED',
        ]);

        $this->actingAs(User::factory()->admin()->create());
        $data = $this->dashboard('?date_from=2026-08-01&date_to=2026-08-31');

        // Encerramentos EM agosto: A e C. (Antes devolvia 3, contando B e D.)
        $this->assertSame(2, $data['volume']['neurology_closures']);
        // Altas hospitalares EM agosto: só A.
        $this->assertSame(1, $data['volume']['hospital_discharges']);
        // A amostra de permanência hospitalar segue a alta, não a entrada.
        $this->assertSame(1, $data['length_of_stay']['hospital_los_days']['n']);
        // Permanência da Neurologia: episódios encerrados em agosto (A e C),
        // com a duração INTEIRA de cada um, não recortada no mês.
        $this->assertSame(2, $data['length_of_stay']['neurology_followup_days']['n']);
    }

    /** Filtrando agosto, o painel ainda contava uma reinternação de março. */
    public function test_readmissions_only_count_reentries_inside_the_window(): void
    {
        Carbon::setTestNow('2026-08-31 10:00:00');
        $paciente = $this->patient('Reinternado');

        // Par de março: alta 05/03, reentrada 08/03 (3 dias).
        $this->episode($paciente, [
            'admission_at' => '2026-03-01 08:00', 'neurology_followup_started_at' => '2026-03-01 08:00',
            'neurology_followup_closed_at' => '2026-03-05 08:00', 'status' => 'CLOSED',
        ]);
        $this->episode($paciente, [
            'admission_at' => '2026-03-08 08:00', 'neurology_followup_started_at' => '2026-03-08 08:00',
            'neurology_followup_closed_at' => '2026-03-10 08:00', 'status' => 'CLOSED',
        ]);
        // Reentrada em agosto, 20 dias depois do encerramento de 12/08.
        $this->episode($paciente, [
            'admission_at' => '2026-08-01 08:00', 'neurology_followup_started_at' => '2026-08-01 08:00',
            'neurology_followup_closed_at' => '2026-08-12 08:00', 'status' => 'CLOSED',
        ]);
        $this->episode($paciente, [
            'admission_at' => '2026-08-25 08:00', 'neurology_followup_started_at' => '2026-08-25 08:00',
            'status' => 'ACTIVE',
        ]);

        $this->actingAs(User::factory()->admin()->create());
        $readmissions = $this->dashboard('?date_from=2026-08-01&date_to=2026-08-31')['readmissions'];

        // Duas reentradas caem na janela: a de 01/08 (144 dias depois do
        // encerramento de março — reentrada, mas não reinternação precoce) e a
        // de 25/08 (13 dias depois do encerramento de 12/08). O par de março,
        // que o cálculo antigo contava mesmo filtrando agosto, sumiu.
        $this->assertSame(2, $readmissions['transitions']);
        $this->assertSame(0, $readmissions['within_7_days']);
        $this->assertSame(1, $readmissions['within_30_days']);
    }

    /**
     * `first_neurology_evaluation_at` só era escrito no encerramento de
     * avaliação única — na interconsulta com acompanhamento contínuo ficava
     * nulo para sempre e o tempo de resposta do serviço nunca tinha amostra.
     */
    public function test_first_evaluation_is_recorded_on_the_first_signed_visit(): void
    {
        Carbon::setTestNow('2026-08-10 08:00:00');
        $med = User::factory()->create();
        CID10::create(['code' => 'G40.9', 'description' => 'Epilepsia', 'category' => 'G40', 'normalized_description' => 'e']);

        $a = $this->episode($this->patient('Interconsulta'), [
            'admission_at' => '2026-08-10 06:00',
            'neurology_followup_started_at' => '2026-08-10 06:00',
            'consult_requested_at' => '2026-08-10 06:00',
            'care_type' => 'INTERCONSULT',
            'status' => 'ACTIVE',
        ]);

        $this->assertNull($a->first_neurology_evaluation_at);

        $this->actingAs($med);
        Carbon::setTestNow('2026-08-11 06:00:00');
        $this->postJson("/api/admissions/{$a->id}/rounds/complete")->assertOk();

        $a->refresh();
        $this->assertNotNull($a->first_neurology_evaluation_at);
        $this->assertSame('2026-08-11 06:00:00', $a->first_neurology_evaluation_at->toDateTimeString());

        // A segunda visita não reescreve: é a PRIMEIRA avaliação.
        Carbon::setTestNow('2026-08-12 06:00:00');
        $this->postJson("/api/admissions/{$a->id}/rounds/complete")->assertOk();
        $this->assertSame('2026-08-11 06:00:00', $a->fresh()->first_neurology_evaluation_at->toDateTimeString());

        $this->actingAs(User::factory()->admin()->create());
        $interconsults = $this->dashboard()['interconsults'];
        $this->assertSame(1, $interconsults['response_time_days']['n']);
        $this->assertEqualsWithDelta(1.0, $interconsults['response_time_days']['median'], 0.01);
        $this->assertSame(0, $interconsults['awaiting_first_evaluation']);
    }

    /**
     * Assinar visita não pode incrementar `version`: o optimistic lock que a
     * tela tem em mãos ficaria velho e o encerramento seguinte tomaria 409.
     */
    public function test_signing_a_visit_does_not_invalidate_the_optimistic_lock(): void
    {
        Carbon::setTestNow('2026-08-10 08:00:00');
        $med = User::factory()->create();

        $a = $this->episode($this->patient('Trava'), [
            'admission_at' => '2026-08-10 06:00',
            'neurology_followup_started_at' => '2026-08-10 06:00',
            'status' => 'ACTIVE',
        ]);
        $versaoAntes = $a->version;

        $this->actingAs($med);
        $this->postJson("/api/admissions/{$a->id}/rounds/complete")->assertOk();

        $this->assertSame($versaoAntes, $a->fresh()->version);

        // E a edição com a versão que o cliente já tinha continua passando.
        $this->putJson("/api/admissions/{$a->id}", ['version' => $versaoAntes, 'bed' => '12'])->assertOk();
    }

    /** Duas internações do mesmo paciente contavam como dois pacientes. */
    public function test_physician_table_counts_patients_and_real_first_evaluations(): void
    {
        Carbon::setTestNow('2026-08-31 10:00:00');
        $medA = User::factory()->create(['full_name' => 'Medica A']);
        $medB = User::factory()->create(['full_name' => 'Medico B']);
        $paciente = $this->patient('Duas internacoes');

        $e1 = $this->episode($paciente, [
            'admission_at' => '2026-08-01 08:00', 'neurology_followup_started_at' => '2026-08-01 08:00',
            'neurology_followup_closed_at' => '2026-08-03 08:00', 'status' => 'CLOSED',
        ]);
        $e2 = $this->episode($paciente, [
            'admission_at' => '2026-08-15 08:00', 'neurology_followup_started_at' => '2026-08-15 08:00',
            'status' => 'ACTIVE',
        ]);

        // Médica A abriu os dois episódios; Médico B fez uma visita de seguimento.
        $this->visit($e1, '2026-08-01', $medA);
        $this->visit($e2, '2026-08-15', $medA);
        $this->visit($e2, '2026-08-16', $medB);

        $this->actingAs(User::factory()->admin()->create());
        $linhas = collect($this->dashboard('?date_from=2026-08-01&date_to=2026-08-31')['physicians']['by_physician'])
            ->keyBy('physician');

        $this->assertSame(2, $linhas['Medica A']['episodes']);
        $this->assertSame(1, $linhas['Medica A']['unique_patients']);
        $this->assertSame(2, $linhas['Medica A']['first_evaluations']);

        $this->assertSame(1, $linhas['Medico B']['episodes']);
        $this->assertSame(1, $linhas['Medico B']['unique_patients']);
        // Visitou, mas não foi quem fez a primeira avaliação.
        $this->assertSame(0, $linhas['Medico B']['first_evaluations']);
    }

    /**
     * Particular + Plano tem de fechar com o total de episódios. Hoje
     * `payer_type` é NOT NULL no banco, então NOT_INFORMED é sempre 0 — o
     * bucket existe como guarda: se alguém tornar a coluna anulável no futuro,
     * é este teste que avisa, em vez de o episódio sumir em silêncio da conta.
     */
    public function test_payer_breakdown_adds_up_to_the_episode_total(): void
    {
        Carbon::setTestNow('2026-08-31 10:00:00');
        $plan = HealthPlan::create(['name' => 'Bradesco', 'normalized_name' => 'bradesco', 'active' => true]);

        $this->episode($this->patient('Part'), [
            'admission_at' => '2026-08-01 08:00', 'neurology_followup_started_at' => '2026-08-01 08:00',
            'payer_type' => 'PRIVATE', 'status' => 'ACTIVE',
        ]);
        $this->episode($this->patient('Convenio'), [
            'admission_at' => '2026-08-02 08:00', 'neurology_followup_started_at' => '2026-08-02 08:00',
            'payer_type' => 'HEALTH_PLAN', 'health_plan_id' => $plan->id,
            'health_plan_name_snapshot' => $plan->name, 'status' => 'ACTIVE',
        ]);

        $this->actingAs(User::factory()->admin()->create());
        $data = $this->dashboard('?date_from=2026-08-01&date_to=2026-08-31');
        $payers = $data['payers']['private_vs_plan'];

        $this->assertSame(
            $data['volume']['episodes'],
            $payers['PRIVATE'] + $payers['HEALTH_PLAN'] + $payers['NOT_INFORMED']
        );
        $this->assertSame(0, $payers['NOT_INFORMED']);
        $this->assertSame(1, $data['payers']['by_plan'][0]['episodes']);
    }

    /** Mediana sem n é indistinguível de coincidência. */
    public function test_every_median_carries_its_sample_size(): void
    {
        Carbon::setTestNow('2026-08-31 10:00:00');
        $this->episode($this->patient('Um'), [
            'admission_at' => '2026-08-01 08:00', 'neurology_followup_started_at' => '2026-08-01 08:00',
            'neurology_followup_closed_at' => '2026-08-04 08:00', 'status' => 'CLOSED',
        ]);

        $this->actingAs(User::factory()->admin()->create());
        $data = $this->dashboard('?date_from=2026-08-01&date_to=2026-08-31');

        $this->assertSame(1, $data['length_of_stay']['neurology_followup_days']['n']);
        $this->assertSame(0, $data['length_of_stay']['hospital_los_days']['n']);
        $this->assertArrayHasKey('n', $data['interconsults']['response_time_days']);
        $this->assertArrayHasKey('n', $data['pending_items']['resolution_days']);
    }

    /** Inverter as datas devolvia relatório vazio, como se não houvesse movimento. */
    public function test_inverted_date_range_is_rejected_instead_of_returning_an_empty_report(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->getJson('/api/admin/dashboard?date_from=2026-08-31&date_to=2026-08-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('date_to');
    }

    /** Mudança de código dentro da mesma categoria não é mudança de doença. */
    public function test_diagnostic_agreement_separates_a_code_change_from_a_disease_change(): void
    {
        Carbon::setTestNow('2026-08-31 10:00:00');
        $med = User::factory()->create();

        foreach ([['G40.9', 'G40.8'], ['G40.9', 'I63.9'], ['G40.9', 'G40.9']] as $i => [$hipotese, $final]) {
            foreach ([$hipotese, $final] as $code) {
                CID10::firstOrCreate(['code' => $code], [
                    'description' => 'D'.$code, 'category' => substr($code, 0, 3), 'normalized_description' => 'd',
                ]);
            }

            $a = $this->episode($this->patient('Caso '.$i), [
                'admission_at' => '2026-08-01 08:00', 'neurology_followup_started_at' => '2026-08-01 08:00',
                'neurology_followup_closed_at' => '2026-08-05 08:00', 'status' => 'CLOSED',
            ]);
            AdmissionDiagnosis::create(['admission_id' => $a->id, 'phase' => 'SUSPECTED', 'cid_code' => $hipotese, 'description_snapshot' => 'x', 'is_primary' => true, 'created_by' => $med->id]);
            AdmissionDiagnosis::create(['admission_id' => $a->id, 'phase' => 'FINAL', 'cid_code' => $final, 'description_snapshot' => 'x', 'is_primary' => true, 'created_by' => $med->id]);
        }

        $this->actingAs(User::factory()->admin()->create());
        $agreement = $this->dashboard('?date_from=2026-08-01&date_to=2026-08-31')['diagnostic_agreement'];

        $this->assertSame(3, $agreement['evaluated']);
        $this->assertSame(1, $agreement['concordant']);
        $this->assertSame(2, $agreement['changed']);
        $this->assertSame(1, $agreement['changed_same_category']);
    }

    /** Comparativo com o período anterior, de mesma duração. */
    public function test_previous_period_uses_the_immediately_preceding_window_of_equal_length(): void
    {
        Carbon::setTestNow('2026-08-31 10:00:00');

        $this->episode($this->patient('Julho'), [
            'admission_at' => '2026-07-10 08:00', 'neurology_followup_started_at' => '2026-07-10 08:00',
            'neurology_followup_closed_at' => '2026-07-12 08:00', 'status' => 'CLOSED',
        ]);
        $this->episode($this->patient('Agosto 1'), [
            'admission_at' => '2026-08-10 08:00', 'neurology_followup_started_at' => '2026-08-10 08:00',
            'neurology_followup_closed_at' => '2026-08-12 08:00', 'status' => 'CLOSED',
        ]);
        $this->episode($this->patient('Agosto 2'), [
            'admission_at' => '2026-08-20 08:00', 'neurology_followup_started_at' => '2026-08-20 08:00',
            'status' => 'ACTIVE',
        ]);

        $this->actingAs(User::factory()->admin()->create());
        $data = $this->dashboard('?date_from=2026-08-01&date_to=2026-08-31');

        $this->assertSame(2, $data['volume']['episodes']);
        $this->assertSame('2026-07-01', $data['previous_period']['from']);
        $this->assertSame('2026-07-31', $data['previous_period']['to']);
        $this->assertSame(1, $data['previous_period']['episodes']);
    }

    /** A série mensal é o que transforma a fotografia em acompanhamento. */
    public function test_monthly_series_breaks_the_window_down_month_by_month(): void
    {
        Carbon::setTestNow('2026-08-31 10:00:00');

        $this->episode($this->patient('Junho'), [
            'admission_at' => '2026-06-10 08:00', 'neurology_followup_started_at' => '2026-06-10 08:00',
            'neurology_followup_closed_at' => '2026-06-12 08:00', 'status' => 'CLOSED',
        ]);
        $this->episode($this->patient('Agosto'), [
            'admission_at' => '2026-08-10 08:00', 'neurology_followup_started_at' => '2026-08-10 08:00',
            'status' => 'ACTIVE',
        ]);

        $this->actingAs(User::factory()->admin()->create());
        $series = collect($this->dashboard('?date_from=2026-06-01&date_to=2026-08-31')['monthly_series'])
            ->keyBy('month');

        $this->assertSame(['2026-06', '2026-07', '2026-08'], $series->keys()->all());
        $this->assertSame(1, $series['2026-06']['episodes']);
        $this->assertSame(0, $series['2026-07']['episodes']);
        $this->assertSame(1, $series['2026-08']['episodes']);
        $this->assertSame(1, $series['2026-06']['neurology_closures']);
    }
}
