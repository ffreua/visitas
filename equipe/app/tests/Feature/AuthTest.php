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
}
