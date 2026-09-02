<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Atualização da estrutura do banco pela tela de Sistema. Existe porque a
 * hospedagem do serviço não tem terminal — sem isso, subir uma versão que
 * adiciona coluna deixaria o site com erro 500 e nenhum caminho de conserto.
 */
class MigrationMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_physician_cannot_read_or_apply_migrations(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/admin/system/migration-status')->assertForbidden();
        $this->postJson('/api/admin/system/apply-migrations', [
            'password' => 'senha@1234',
            'confirmation_phrase' => 'ATUALIZAR BANCO',
        ])->assertForbidden();
    }

    public function test_admin_sees_migration_status(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $data = $this->getJson('/api/admin/system/migration-status')->assertOk()->json();

        // O banco de teste é recriado do zero a cada execução: tudo aplicado.
        $this->assertTrue($data['up_to_date']);
        $this->assertSame([], $data['pending']);
        $this->assertGreaterThan(0, $data['ran']);
    }

    public function test_apply_requires_confirmation_phrase_and_correct_password(): void
    {
        $admin = User::factory()->admin()->create(['password' => bcrypt('senha-admin')]);
        $this->actingAs($admin);

        $this->postJson('/api/admin/system/apply-migrations', [
            'password' => 'senha-admin',
            'confirmation_phrase' => 'atualizar',
        ])->assertStatus(422)->assertJsonValidationErrors('confirmation_phrase');

        $this->postJson('/api/admin/system/apply-migrations', [
            'password' => 'errada',
            'confirmation_phrase' => 'ATUALIZAR BANCO',
        ])->assertStatus(403);
    }

    public function test_apply_is_a_noop_when_database_is_already_up_to_date(): void
    {
        $admin = User::factory()->admin()->create(['password' => bcrypt('senha-admin')]);
        $this->actingAs($admin);

        $data = $this->postJson('/api/admin/system/apply-migrations', [
            'password' => 'senha-admin',
            'confirmation_phrase' => 'ATUALIZAR BANCO',
        ])->assertOk()->json();

        $this->assertSame([], $data['applied']);
        $this->assertNull($data['safety_backup']);
    }
}
