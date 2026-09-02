<?php

namespace Tests\Feature;

use App\Models\CID10;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Edição e exclusão de membros da equipe. As duas regras que sustentam o
 * resto: quem já assinou ato assistencial nunca é excluído (só desativado,
 * para não apagar a autoria do registro), e o sistema nunca pode ficar sem
 * administrador ativo.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $password = 'senha-admin'): User
    {
        return User::factory()->admin()->create(['password' => bcrypt($password)]);
    }

    private function createAdmissionAs(User $physician, string $mrn): void
    {
        CID10::firstOrCreate(['code' => 'G40.9'], [
            'description' => 'Epilepsia', 'category' => 'G40', 'normalized_description' => 'epilepsia',
        ]);

        $patient = Patient::create([
            'medical_record_number' => $mrn,
            'full_name' => 'Paciente '.$mrn,
            'date_of_birth' => '1980-01-01',
        ]);

        $this->actingAs($physician)->postJson('/api/admissions', [
            'patient_id' => $patient->id,
            'admission_at' => now()->toDateTimeString(),
            'care_type' => 'INSTITUTIONAL',
            'followup_mode' => 'ONGOING',
            'payer_type' => 'PRIVATE',
            'origin' => 'WARD',
            'suspected_cid_code' => 'G40.9',
        ])->assertCreated();
    }

    public function test_physician_cannot_manage_the_team(): void
    {
        $alvo = User::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->putJson("/api/admin/users/{$alvo->id}", ['full_name' => 'Novo Nome'])->assertForbidden();
        $this->deleteJson("/api/admin/users/{$alvo->id}", ['password' => 'x'])->assertForbidden();
    }

    public function test_admin_edits_name_crm_username_and_role(): void
    {
        $this->actingAs($this->admin());
        $alvo = User::factory()->create(['full_name' => 'Nome Errado', 'username' => 'errado']);

        $atualizado = $this->putJson("/api/admin/users/{$alvo->id}", [
            'full_name' => 'Nome Correto',
            'crm' => '12345-SP',
            'username' => 'correto',
            'role' => 'ADMIN',
        ])->assertOk()->json();

        $this->assertSame('Nome Correto', $atualizado['full_name']);
        $this->assertSame('12345-SP', $atualizado['crm']);
        $this->assertSame('correto', $atualizado['username']);
        $this->assertSame('ADMIN', $atualizado['role']);
    }

    public function test_username_must_stay_unique(): void
    {
        $this->actingAs($this->admin());

        User::factory()->create(['username' => 'ocupado']);
        $alvo = User::factory()->create(['username' => 'livre']);

        $this->putJson("/api/admin/users/{$alvo->id}", ['username' => 'ocupado'])
            ->assertStatus(422)->assertJsonValidationErrors('username');

        // O próprio username não pode colidir consigo mesmo.
        $this->putJson("/api/admin/users/{$alvo->id}", ['username' => 'livre'])->assertOk();
    }

    public function test_admin_deletes_a_user_who_never_took_part_in_care(): void
    {
        $this->actingAs($this->admin());
        $alvo = User::factory()->create();

        $this->deleteJson("/api/admin/users/{$alvo->id}", ['password' => 'errada'])->assertStatus(403);
        $this->assertModelExists($alvo);

        $this->deleteJson("/api/admin/users/{$alvo->id}", ['password' => 'senha-admin'])->assertOk();
        $this->assertModelMissing($alvo);
    }

    /**
     * O ponto central: excluir quem já assinou algo apagaria a autoria do
     * registro clínico. A API recusa e aponta o caminho certo (desativar).
     */
    public function test_user_with_clinical_footprint_cannot_be_deleted(): void
    {
        $medico = User::factory()->create();
        $this->createAdmissionAs($medico, 'FOOT-1');

        $this->actingAs($this->admin());

        $this->deleteJson("/api/admin/users/{$medico->id}", ['password' => 'senha-admin'])
            ->assertStatus(422);

        $this->assertModelExists($medico);

        // Desativar continua funcionando e é o caminho indicado.
        $this->postJson("/api/admin/users/{$medico->id}/deactivate")->assertOk();
        $this->assertFalse($medico->fresh()->active);
    }

    public function test_index_flags_whether_each_user_can_be_deleted(): void
    {
        $medico = User::factory()->create();
        $this->createAdmissionAs($medico, 'FLAG-1');
        $novato = User::factory()->create();

        $this->actingAs($this->admin());
        $linhas = collect($this->getJson('/api/admin/users')->assertOk()->json('data'))->keyBy('id');

        $this->assertFalse($linhas[$medico->id]['can_delete']);
        $this->assertTrue($linhas[$novato->id]['can_delete']);
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $this->deleteJson("/api/admin/users/{$admin->id}", ['password' => 'senha-admin'])
            ->assertForbidden();

        $this->assertModelExists($admin);
    }

    /**
     * Sem esta trava o sistema poderia ficar sem nenhum administrador — e
     * não há como promover alguém a admin sem já estar logado como admin.
     */
    public function test_the_last_active_admin_cannot_be_demoted_deactivated_or_deleted(): void
    {
        $unico = $this->admin();
        $outroAdmin = $this->admin('outra-senha');

        // Com dois admins ativos, mexer em um deles é permitido.
        $this->actingAs($unico);
        $this->postJson("/api/admin/users/{$outroAdmin->id}/deactivate")->assertOk();

        // Agora só resta um admin ativo: ele fica travado.
        $this->putJson("/api/admin/users/{$unico->id}", ['role' => 'PHYSICIAN'])
            ->assertStatus(422)->assertJsonValidationErrors('role');

        $this->postJson("/api/admin/users/{$unico->id}/deactivate")
            ->assertStatus(422)->assertJsonValidationErrors('active');

        $this->assertSame('ADMIN', $unico->fresh()->role);
        $this->assertTrue($unico->fresh()->active);
    }
}
