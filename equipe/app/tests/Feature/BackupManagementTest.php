<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Backups não podem crescer indefinidamente. Duas garantias:
 * a retenção roda sozinha a cada backup criado (inclusive os criados pela
 * web, já que a hospedagem não tem cron), e o administrador consegue
 * excluir um arquivo específico pelo painel.
 */
class BackupManagementTest extends TestCase
{
    use RefreshDatabase;

    private string $backupsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupsPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'neuro-backups-'.uniqid();
        mkdir($this->backupsPath);
        config(['neurologia.backups_path' => $this->backupsPath]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupsPath.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->backupsPath);

        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeBackup(string $timestamp): string
    {
        $path = $this->backupsPath.DIRECTORY_SEPARATOR."neurologia_{$timestamp}.sqlite3";
        file_put_contents($path, 'conteudo-fake');

        return $path;
    }

    public function test_physician_cannot_manage_backups(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/admin/system/backups')->assertForbidden();
        $this->deleteJson('/api/admin/system/backups/neurologia_2026-01-01_000000.sqlite3', ['password' => 'x'])
            ->assertForbidden();
        $this->postJson('/api/admin/system/backups/prune', ['password' => 'x'])->assertForbidden();
    }

    public function test_listing_reports_retention_policy_and_total_size(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->fakeBackup('2026-09-01_120000');
        $this->fakeBackup('2026-08-01_120000');

        $data = $this->getJson('/api/admin/system/backups')->assertOk()->json();

        $this->assertCount(2, $data['backups']);
        $this->assertSame(strlen('conteudo-fake') * 2, $data['total_size']);
        $this->assertSame(config('neurologia.backup_retention'), $data['retention']);
    }

    public function test_admin_deletes_a_backup_with_reauth(): void
    {
        $admin = User::factory()->admin()->create(['password' => bcrypt('senha-admin')]);
        $this->actingAs($admin);

        $path = $this->fakeBackup('2026-09-01_120000');

        $this->deleteJson('/api/admin/system/backups/neurologia_2026-09-01_120000.sqlite3', ['password' => 'errada'])
            ->assertStatus(403);
        $this->assertFileExists($path);

        $this->deleteJson('/api/admin/system/backups/neurologia_2026-09-01_120000.sqlite3', ['password' => 'senha-admin'])
            ->assertOk();
        $this->assertFileDoesNotExist($path);
    }

    /**
     * O nome do arquivo vem da URL: qualquer coisa fora do padrão gerado
     * por BackupService::create() não pode chegar perto do filesystem.
     */
    public function test_delete_rejects_anything_that_is_not_a_backup_filename(): void
    {
        $admin = User::factory()->admin()->create(['password' => bcrypt('senha-admin')]);
        $this->actingAs($admin);

        $intruso = $this->backupsPath.DIRECTORY_SEPARATOR.'importante.txt';
        file_put_contents($intruso, 'nao apague');

        $this->deleteJson('/api/admin/system/backups/importante.txt', ['password' => 'senha-admin'])
            ->assertStatus(422);

        $this->assertFileExists($intruso);
        @unlink($intruso);
    }

    public function test_prune_applies_the_retention_policy_on_demand(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00');

        $admin = User::factory()->admin()->create(['password' => bcrypt('senha-admin')]);
        $this->actingAs($admin);

        $recente = $this->fakeBackup('2026-09-01_100000');
        $antigo = $this->fakeBackup('2020-01-01_100000');

        $data = $this->postJson('/api/admin/system/backups/prune', ['password' => 'senha-admin'])
            ->assertOk()->json();

        $this->assertSame(1, $data['removed']);
        $this->assertFileExists($recente);
        $this->assertFileDoesNotExist($antigo);
    }

    /**
     * A retenção precisa rodar dentro do próprio create(): nesta hospedagem
     * os backups nascem pela web (atualização de estrutura, zona de perigo)
     * e não há cron para chamar o comando agendado.
     */
    public function test_creating_a_backup_prunes_old_ones_and_never_the_new_one(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00');

        // create() copia o arquivo do banco; a suíte roda em :memory:, então
        // apontamos a conexão para um SQLite real só para este teste.
        $arquivoDoBanco = $this->backupsPath.DIRECTORY_SEPARATOR.'origem.sqlite3';
        (new \PDO('sqlite:'.$arquivoDoBanco))->exec('CREATE TABLE t (id integer)');
        config(['database.connections.'.config('database.default').'.database' => $arquivoDoBanco]);

        $antigo = $this->fakeBackup('2020-01-01_100000');

        $resultado = app(BackupService::class)->create();

        $this->assertSame(1, $resultado['pruned']);
        $this->assertFileDoesNotExist($antigo);
        $this->assertFileExists($resultado['path']);
    }
}
