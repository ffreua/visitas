<?php

namespace Tests\Feature;

use App\Models\CID10;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DailyRoundTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Critical test — seção 113 do PRD: responsável reseta a cada novo dia,
     * mas o histórico do dia anterior permanece intacto.
     */
    public function test_responsible_physician_resets_daily_without_losing_history(): void
    {
        Carbon::setTestNow('2026-08-14 09:00:00');

        $physicianDay1 = User::factory()->create(['full_name' => 'Dr. João']);
        $physicianDay2 = User::factory()->create(['full_name' => 'Dra. Maria']);
        $this->actingAs($physicianDay1);

        CID10::create(['code' => 'G40.9', 'description' => 'Epilepsia', 'category' => 'G40', 'normalized_description' => 'epilepsia']);
        $patient = Patient::create([
            'medical_record_number' => '444555',
            'full_name' => 'Paciente Rounds',
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

        $this->postJson("/api/admissions/{$admission['id']}/rounds/assign", [
            'assigned_physician_id' => $physicianDay1->id,
        ])->assertOk();

        $this->postJson("/api/admissions/{$admission['id']}/rounds/complete")->assertOk();

        $onDate = fn ($round, $date) => str_starts_with($round['round_date'], $date);

        $detail = $this->getJson("/api/admissions/{$admission['id']}")->json();
        $this->assertCount(1, $detail['daily_rounds']);
        $this->assertTrue($onDate($detail['daily_rounds'][0], '2026-08-14'));
        $this->assertSame($physicianDay1->id, $detail['daily_rounds'][0]['assigned_physician_id']);
        $this->assertNotNull($detail['daily_rounds'][0]['completed_at']);

        // Avança para o dia seguinte — nenhum round novo existe ainda "hoje".
        Carbon::setTestNow('2026-08-15 08:00:00');

        $detailDay2 = $this->getJson("/api/admissions/{$admission['id']}")->json();
        $todayRound = collect($detailDay2['daily_rounds'])->first(fn ($r) => $onDate($r, '2026-08-15'));
        $this->assertNull($todayRound, 'Não deve existir round para hoje até alguém assumir.');

        $yesterdayRound = collect($detailDay2['daily_rounds'])->first(fn ($r) => $onDate($r, '2026-08-14'));
        $this->assertNotNull($yesterdayRound, 'Histórico de ontem deve permanecer.');
        $this->assertSame($physicianDay1->id, $yesterdayRound['assigned_physician_id']);

        $this->postJson("/api/admissions/{$admission['id']}/rounds/assign", [
            'assigned_physician_id' => $physicianDay2->id,
        ])->assertOk();

        $detailFinal = $this->getJson("/api/admissions/{$admission['id']}")->json();
        $this->assertCount(2, $detailFinal['daily_rounds']);
    }
}
