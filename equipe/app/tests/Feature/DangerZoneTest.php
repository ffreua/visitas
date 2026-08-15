<?php

namespace Tests\Feature;

use App\Models\CID10;
use App\Models\HealthPlan;
use App\Models\MedicalSpecialty;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DangerZoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_physician_cannot_access_danger_zone(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/admin/system/reset-clinical-data', [
            'password' => 'whatever', 'confirmation_phrase' => 'ZERAR DADOS CLINICOS',
        ])->assertForbidden();
    }

    public function test_wrong_password_is_rejected(): void
    {
        $admin = User::factory()->admin()->create(['password' => bcrypt('senha-admin')]);
        $this->actingAs($admin);

        $this->postJson('/api/admin/system/reset-clinical-data', [
            'password' => 'errada', 'confirmation_phrase' => 'ZERAR DADOS CLINICOS',
        ])->assertStatus(403);
    }

    public function test_wrong_confirmation_phrase_is_rejected(): void
    {
        $admin = User::factory()->admin()->create(['password' => bcrypt('senha-admin')]);
        $this->actingAs($admin);

        $this->postJson('/api/admin/system/reset-clinical-data', [
            'password' => 'senha-admin', 'confirmation_phrase' => 'zerar tudo',
        ])->assertStatus(422);
    }

    /**
     * Seção 97 do PRD: "Se backup falhar: ABORTAR". Em teste padrão o SQLite
     * é ":memory:" (phpunit.xml) — não existe arquivo para copiar, então o
     * backup de segurança falha organicamente. Isso prova o caminho de
     * aborto sem precisar simular a falha artificialmente.
     */
    public function test_aborts_without_deleting_anything_when_safety_backup_fails(): void
    {
        $admin = User::factory()->admin()->create(['password' => bcrypt('senha-admin')]);
        $this->actingAs($admin);

        $patient = Patient::create(['medical_record_number' => '1', 'full_name' => 'Paciente', 'date_of_birth' => '1980-01-01']);

        $response = $this->postJson('/api/admin/system/reset-clinical-data', [
            'password' => 'senha-admin', 'confirmation_phrase' => 'ZERAR DADOS CLINICOS',
        ])->assertStatus(422);

        $this->assertStringContainsString('ABORTADO', $response->json('message'));
        $this->assertDatabaseHas('patients', ['id' => $patient->id]);
    }

    /**
     * Teste dedicado com banco SQLite real em arquivo (não ":memory:") —
     * necessário porque o backup de segurança precisa copiar um arquivo de
     * verdade. Usa uma conexão nomeada separada ("sqlite_danger_zone_test")
     * e troca `database.default` para apontar pra ela temporariamente —
     * a conexão "sqlite" (:memory:) do RefreshDatabase nunca é tocada/
     * purgada, porque o Laravel mantém essa conexão em memória viva por
     * TODA a execução do PHPUnit (mesmo processo PHP); um DB::purge('sqlite')
     * nela destruiria o schema para os testes seguintes de outras classes.
     */
    public function test_reset_wipes_clinical_data_but_preserves_reference_data(): void
    {
        $tempDb = tempnam(sys_get_temp_dir(), 'neuro_test_db_').'.sqlite3';
        touch($tempDb);
        $tempBackupsDir = sys_get_temp_dir().'/neuro_test_backups_'.uniqid();
        mkdir($tempBackupsDir);

        $originalDefaultConnection = config('database.default');
        $originalBackupsPath = config('neurologia.backups_path');

        config(['database.connections.sqlite_danger_zone_test' => array_merge(
            config('database.connections.sqlite'),
            ['database' => $tempDb]
        )]);
        config(['database.default' => 'sqlite_danger_zone_test']);
        config(['neurologia.backups_path' => $tempBackupsDir]);
        Artisan::call('migrate', ['--database' => 'sqlite_danger_zone_test', '--force' => true]);

        try {
            $admin = User::factory()->admin()->create(['password' => bcrypt('senha-admin')]);
            $this->actingAs($admin);

            CID10::create(['code' => 'G40.9', 'description' => 'Epilepsia', 'category' => 'G40', 'normalized_description' => 'epilepsia']);
            HealthPlan::create(['name' => 'Bradesco', 'normalized_name' => 'bradesco', 'active' => true]);
            MedicalSpecialty::create(['name' => 'Cardiologia', 'normalized_name' => 'cardiologia', 'active' => true]);
            $patient = Patient::create(['medical_record_number' => '1', 'full_name' => 'Paciente', 'date_of_birth' => '1980-01-01']);

            $this->postJson('/api/admissions', [
                'patient_id' => $patient->id, 'admission_at' => now()->toDateTimeString(),
                'care_type' => 'INSTITUTIONAL', 'followup_mode' => 'ONGOING', 'payer_type' => 'PRIVATE',
                'suspected_cid_code' => 'G40.9',
            ])->assertCreated();

            $response = $this->postJson('/api/admin/system/reset-clinical-data', [
                'password' => 'senha-admin', 'confirmation_phrase' => 'ZERAR DADOS CLINICOS',
            ])->assertOk();

            $this->assertDatabaseCount('admissions', 0);
            $this->assertDatabaseCount('patients', 0);
            $this->assertDatabaseCount('admission_diagnoses', 0);

            // Dados de referência preservados.
            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseHas('cid10', ['code' => 'G40.9']);
            $this->assertDatabaseHas('health_plans', ['name' => 'Bradesco']);
            $this->assertDatabaseHas('medical_specialties', ['name' => 'Cardiologia']);

            // Backup de segurança foi de fato criado no disco.
            $safetyBackupFilename = $response->json('safety_backup');
            $this->assertFileExists($tempBackupsDir.'/'.$safetyBackupFilename);

            // O próprio evento RESET_DATABASE ficou registrado na auditoria.
            $this->assertDatabaseHas('audit_logs', ['action' => 'RESET_DATABASE']);
        } finally {
            // Restaura ANTES de apagar os arquivos: no Windows não dá pra
            // apagar um arquivo com uma conexão PDO ainda aberta nele.
            config(['database.default' => $originalDefaultConnection]);
            config(['neurologia.backups_path' => $originalBackupsPath]);
            DB::purge('sqlite_danger_zone_test');

            foreach (glob("{$tempBackupsDir}/*") ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tempBackupsDir);
            @unlink($tempDb);
            @unlink($tempDb.'-wal');
            @unlink($tempDb.'-shm');
        }
    }
}
