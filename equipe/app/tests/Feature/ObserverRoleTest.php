<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gestor observador (OBSERVER): vê a lista assistencial, a movimentação da
 * equipe e os dashboards — e não escreve nada, em lugar nenhum, nem exporta.
 * Cada teste aqui vale por uma promessa feita ao papel.
 */
class ObserverRoleTest extends TestCase
{
    use RefreshDatabase;

    private function admissionForTest(): Admission
    {
        $physician = User::factory()->create();

        $patient = Patient::create([
            'full_name' => 'Paciente Observado',
            'medical_record_number' => 'MR-OBS-1',
            'date_of_birth' => '1955-04-10',
        ]);

        return Admission::create([
            'patient_id' => $patient->id,
            'admission_at' => now()->subDay(),
            'care_type' => 'INSTITUTIONAL',
            'followup_mode' => 'ONGOING',
            'payer_type' => 'PRIVATE',
            'created_by' => $physician->id,
        ]);
    }

    public function test_observer_reads_the_active_list_and_a_case_in_full(): void
    {
        $admission = $this->admissionForTest();

        $this->actingAs(User::factory()->observer()->create());

        $this->getJson('/api/admissions')->assertOk()->assertJsonPath('data.0.id', $admission->id);
        $this->getJson("/api/admissions/{$admission->id}")->assertOk();
        $this->getJson('/api/admissions/closed')->assertOk();
        $this->getJson("/api/patients/{$admission->patient_id}/history")->assertOk();
    }

    public function test_observer_reads_the_dashboards(): void
    {
        $this->admissionForTest();

        $this->actingAs(User::factory()->observer()->create());

        $this->getJson('/api/admin/dashboard')->assertOk();
        $this->getJson('/api/admin/dashboard/data-quality')->assertOk();
        $this->getJson('/api/admin/dashboard/patients')->assertOk();
    }

    public function test_observer_cannot_write_anything(): void
    {
        $admission = $this->admissionForTest();
        $physician = User::factory()->create();

        $this->actingAs(User::factory()->observer()->create());

        // Assistencial.
        $this->postJson('/api/admissions', [])->assertForbidden();
        $this->putJson("/api/admissions/{$admission->id}", ['version' => $admission->version, 'bed' => '7'])->assertForbidden();
        $this->deleteJson("/api/admissions/{$admission->id}", ['reason' => 'DUPLICATE'])->assertForbidden();
        $this->postJson("/api/admissions/{$admission->id}/close", [])->assertForbidden();
        $this->postJson("/api/admissions/{$admission->id}/pending-items", ['description' => 'x'])->assertForbidden();
        $this->postJson("/api/admissions/{$admission->id}/diagnoses", [])->assertForbidden();
        $this->postJson("/api/admissions/{$admission->id}/rounds/assign", ['assigned_physician_id' => $physician->id])->assertForbidden();
        $this->postJson("/api/admissions/{$admission->id}/rounds/complete", [])->assertForbidden();
        $this->postJson('/api/patients', ['full_name' => 'Novo'])->assertForbidden();
        $this->putJson("/api/patients/{$admission->patient_id}", ['full_name' => 'Outro'])->assertForbidden();

        // Nada foi tocado no banco.
        $this->assertDatabaseCount('pending_items', 0);
        $this->assertDatabaseCount('daily_rounds', 0);
        $this->assertSame('Paciente Observado', $admission->patient->fresh()->full_name);
    }

    public function test_observer_cannot_export_files(): void
    {
        $this->actingAs(User::factory()->observer()->create());

        $this->postJson('/api/admin/exports', ['pseudonymized' => true])->assertForbidden();
        $this->getJson('/api/admin/exports/qualquer.xlsx/download')->assertForbidden();
    }

    public function test_observer_cannot_manage_team_catalogs_or_system(): void
    {
        $target = User::factory()->create();

        $this->actingAs(User::factory()->observer()->create());

        $this->getJson('/api/admin/users')->assertForbidden();
        $this->postJson('/api/admin/users', ['full_name' => 'X', 'username' => 'x', 'role' => 'ADMIN'])->assertForbidden();
        $this->postJson("/api/admin/users/{$target->id}/reset-password")->assertForbidden();
        $this->getJson('/api/admin/health-plans')->assertForbidden();
        $this->getJson('/api/admin/system/backups')->assertForbidden();
        $this->postJson('/api/admin/system/reset-clinical-data', [])->assertForbidden();
        $this->getJson('/api/admissions/trashed')->assertForbidden();
    }

    public function test_observer_is_not_offered_as_physician_of_the_day(): void
    {
        $physician = User::factory()->create(['full_name' => 'Dra. Plantonista']);
        $observer = User::factory()->observer()->create(['full_name' => 'Gestor Observador']);

        $this->actingAs($physician);

        $response = $this->getJson('/api/physicians')->assertOk();

        $ids = collect($response->json())->pluck('id');
        $this->assertTrue($ids->contains($physician->id));
        $this->assertFalse($ids->contains($observer->id));
    }

    public function test_admin_can_create_and_promote_an_observer(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/users', [
            'full_name' => 'Gestor Observador',
            'username' => 'gestor',
            'role' => 'OBSERVER',
        ])->assertCreated()->assertJsonPath('role', 'OBSERVER');

        $this->assertDatabaseHas('users', ['username' => 'gestor', 'role' => 'OBSERVER']);
    }

    public function test_last_active_admin_cannot_become_an_observer(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->putJson("/api/admin/users/{$admin->id}", ['role' => 'OBSERVER'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertSame('ADMIN', $admin->fresh()->role);
    }

    public function test_observer_can_still_log_out_and_change_own_password(): void
    {
        $observer = User::factory()->observer()->create();
        $this->actingAs($observer);

        $this->postJson('/api/auth/change-password', [
            'current_password' => 'senha@1234',
            'new_password' => 'ObservaSenha#2026',
            'new_password_confirmation' => 'ObservaSenha#2026',
        ])->assertOk();

        $this->postJson('/api/auth/logout')->assertOk();
    }
}
