<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\CID10;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmission(): array
    {
        CID10::create(['code' => 'G40.9', 'description' => 'Epilepsia', 'category' => 'G40', 'normalized_description' => 'epilepsia']);

        $patient = Patient::create([
            'medical_record_number' => '999000',
            'full_name' => 'Paciente Exclusão',
            'date_of_birth' => '1990-01-01',
        ]);

        return $this->postJson('/api/admissions', [
            'patient_id' => $patient->id,
            'admission_at' => now()->toDateTimeString(),
            'care_type' => 'INSTITUTIONAL',
            'followup_mode' => 'ONGOING',
            'payer_type' => 'PRIVATE',
            'suspected_cid_code' => 'G40.9',
        ])->assertCreated()->json();
    }

    /**
     * Critical test — seção 110 do PRD: médico exclui, registro some da
     * interface do médico mas continua existindo no banco (SoftDelete real).
     */
    public function test_physician_soft_delete_hides_from_active_and_history_but_keeps_row(): void
    {
        $physician = User::factory()->create();
        $this->actingAs($physician);

        $created = $this->createAdmission();

        $this->deleteJson("/api/admissions/{$created['id']}", [
            'reason' => 'CREATED_BY_MISTAKE',
        ])->assertOk();

        $this->getJson('/api/admissions')->assertJsonMissing(['id' => $created['id']]);
        $this->getJson('/api/admissions/closed')->assertJsonMissing(['id' => $created['id']]);

        $this->assertDatabaseHas('admissions', ['id' => $created['id']]);
        $row = Admission::withTrashed()->find($created['id']);
        $this->assertNotNull($row->deleted_at);
        $this->assertSame($physician->id, $row->deleted_by);
    }

    public function test_physician_cannot_view_trashed_or_restore_or_force_delete(): void
    {
        $this->actingAs(User::factory()->create());
        $created = $this->createAdmission();
        $this->deleteJson("/api/admissions/{$created['id']}", ['reason' => 'CREATED_BY_MISTAKE']);

        $this->getJson('/api/admissions/trashed')->assertForbidden();
        $this->postJson("/api/admissions/{$created['id']}/restore")->assertForbidden();
        $this->deleteJson("/api/admissions/{$created['id']}/force", [
            'password' => 'whatever',
            'reason' => 'teste',
            'confirmation_phrase' => 'EXCLUIR DEFINITIVAMENTE',
        ])->assertForbidden();
    }

    /**
     * Critical test — seção 112 do PRD.
     */
    public function test_admin_can_restore_soft_deleted_admission(): void
    {
        $physician = User::factory()->create();
        $this->actingAs($physician);
        $created = $this->createAdmission();
        $this->deleteJson("/api/admissions/{$created['id']}", ['reason' => 'CREATED_BY_MISTAKE']);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->getJson('/api/admissions/trashed')->assertJsonFragment(['id' => $created['id']]);

        $this->postJson("/api/admissions/{$created['id']}/restore")->assertOk();

        $row = Admission::find($created['id']);
        $this->assertNotNull($row);
        $this->assertNull($row->deleted_at);
        $this->assertNull($row->deleted_by);
    }

    /**
     * Critical test — seção 111 do PRD.
     */
    public function test_admin_hard_delete_requires_reauth_phrase_and_wipes_row(): void
    {
        $this->actingAs(User::factory()->create());
        $created = $this->createAdmission();
        $this->deleteJson("/api/admissions/{$created['id']}", ['reason' => 'CREATED_BY_MISTAKE']);

        $admin = User::factory()->admin()->create(['password' => bcrypt('admin-secreto')]);
        $this->actingAs($admin);

        // senha errada -> bloqueado
        $this->deleteJson("/api/admissions/{$created['id']}/force", [
            'password' => 'senha-errada',
            'reason' => 'teste',
            'confirmation_phrase' => 'EXCLUIR DEFINITIVAMENTE',
        ])->assertStatus(403);

        // frase errada -> bloqueado
        $this->deleteJson("/api/admissions/{$created['id']}/force", [
            'password' => 'admin-secreto',
            'reason' => 'teste',
            'confirmation_phrase' => 'excluir',
        ])->assertStatus(422);

        $this->deleteJson("/api/admissions/{$created['id']}/force", [
            'password' => 'admin-secreto',
            'reason' => 'teste',
            'confirmation_phrase' => 'EXCLUIR DEFINITIVAMENTE',
        ])->assertOk();

        $this->assertDatabaseMissing('admissions', ['id' => $created['id']]);
    }
}
