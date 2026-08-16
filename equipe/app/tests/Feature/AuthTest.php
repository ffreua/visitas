<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_valid_credentials_succeeds(): void
    {
        User::factory()->create(['username' => 'joao', 'password' => bcrypt('senha-forte-123')]);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'joao',
            'password' => 'senha-forte-123',
        ]);

        $response->assertOk()->assertJsonPath('user.username', 'joao');
        $this->assertAuthenticated();
    }

    public function test_login_with_invalid_credentials_fails(): void
    {
        User::factory()->create(['username' => 'joao', 'password' => bcrypt('senha-forte-123')]);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'joao',
            'password' => 'errada',
        ]);

        $response->assertStatus(422);
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create(['username' => 'joao', 'password' => bcrypt('senha-forte-123'), 'active' => false]);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'joao',
            'password' => 'senha-forte-123',
        ]);

        $response->assertStatus(422);
    }

    public function test_first_login_with_default_password_forces_change(): void
    {
        $user = User::factory()->mustChangePassword()->create([
            'username' => 'novo',
            'password' => bcrypt('senha@1234'),
        ]);

        $this->actingAs($user);

        $response = $this->getJson('/api/patients/lookup?medical_record_number=1');

        $response->assertStatus(423)->assertJsonPath('must_change_password', true);
    }

    public function test_change_password_unlocks_access(): void
    {
        $user = User::factory()->mustChangePassword()->create([
            'username' => 'novo',
            'password' => bcrypt('senha@1234'),
        ]);

        $this->actingAs($user);

        $response = $this->postJson('/api/auth/change-password', [
            'current_password' => 'senha@1234',
            'new_password' => 'outra-senha-forte',
            'new_password_confirmation' => 'outra-senha-forte',
        ]);

        $response->assertOk();
        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_physician_cannot_access_admin_routes(): void
    {
        $physician = User::factory()->create();
        $this->actingAs($physician);

        $this->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_admin_can_access_admin_routes(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->getJson('/api/admin/users')->assertOk();
    }

    /**
     * Regressão: HealthPlanPolicy/MedicalSpecialtyPolicy::viewAny retornavam
     * true para qualquer usuário, então as rotas admin.health-plans.index e
     * admin.medical-specialties.index (listagem completa, inclusive
     * inativos) ficavam acessíveis a PHYSICIAN apesar de estarem sob /admin.
     */
    public function test_physician_cannot_list_all_health_plans_or_specialties_via_admin_routes(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/admin/health-plans')->assertForbidden();
        $this->getJson('/api/admin/medical-specialties')->assertForbidden();
    }

    /**
     * Regressão: desativar um usuário não derrubava sessões já
     * autenticadas — o guard de sessão nunca revalidava `active` depois do
     * login. Sem o middleware "active", este teste falharia com 200/423 em
     * vez de 401.
     */
    /**
     * Regressão: uma requisição sem Accept: application/json (ex.: colar
     * uma URL de /api/* direto no navegador) fazia o Laravel tentar
     * redirecionar pra route('login'), que não existe nesta SPA — virava
     * 500 em vez de um redirect/401 adequado. Clientes reais (axios sempre
     * manda Accept: application/json) nunca passavam por esse caminho.
     */
    public function test_unauthenticated_request_without_json_accept_header_redirects_instead_of_500(): void
    {
        $this->get('/api/auth/me')->assertRedirect('/');

        $this->getJson('/api/auth/me')->assertStatus(401)->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_deactivating_user_kills_their_live_session_immediately(): void
    {
        $physician = User::factory()->create();
        $this->actingAs($physician);

        $this->getJson('/api/patients/lookup?medical_record_number=1')->assertStatus(404);

        $physician->update(['active' => false]);

        $this->getJson('/api/patients/lookup?medical_record_number=1')->assertStatus(401);
        $this->assertGuest();
    }
}
