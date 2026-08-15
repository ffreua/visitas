<?php

namespace Tests\Feature;

use App\Models\CID10;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase;

    private array $generatedFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->generatedFiles as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function createAdmission(): array
    {
        CID10::create(['code' => 'G40.9', 'description' => 'Epilepsia', 'category' => 'G40', 'normalized_description' => 'epilepsia']);
        $patient = Patient::create(['medical_record_number' => 'E001', 'full_name' => 'Paciente Export', 'date_of_birth' => '1980-01-01']);

        return $this->postJson('/api/admissions', [
            'patient_id' => $patient->id, 'admission_at' => now()->toDateTimeString(),
            'care_type' => 'INSTITUTIONAL', 'followup_mode' => 'ONGOING', 'payer_type' => 'PRIVATE',
            'suspected_cid_code' => 'G40.9',
        ])->json();
    }

    public function test_physician_cannot_export(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/admin/exports', ['pseudonymized' => true])->assertForbidden();
    }

    public function test_identifiable_export_requires_correct_password(): void
    {
        $admin = User::factory()->admin()->create(['password' => bcrypt('senha-admin')]);
        $this->actingAs($admin);
        $this->createAdmission();

        $this->postJson('/api/admin/exports', ['pseudonymized' => false, 'password' => 'errada'])
            ->assertStatus(403);

        $response = $this->postJson('/api/admin/exports', ['pseudonymized' => false, 'password' => 'senha-admin'])
            ->assertCreated();

        $token = $response->json('download_token');
        $path = config('neurologia.exports_path').DIRECTORY_SEPARATOR.$token;
        $this->generatedFiles[] = $path;
        $this->assertFileExists($path);
    }

    public function test_pseudonymized_export_does_not_require_password_and_hides_identifiers(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $this->createAdmission();

        $response = $this->postJson('/api/admin/exports', ['pseudonymized' => true])->assertCreated();
        $token = $response->json('download_token');
        $path = config('neurologia.exports_path').DIRECTORY_SEPARATOR.$token;
        $this->generatedFiles[] = $path;

        $spreadsheet = (new Xlsx)->load($path);
        $episodesSheet = $spreadsheet->getSheetByName('Episodios');
        $headerRow = $episodesSheet->rangeToArray('A1:Z1')[0];

        $this->assertNotContains('Paciente', array_filter($headerRow));
        $this->assertContains('Código do paciente', $headerRow);

        $dataRow = $episodesSheet->rangeToArray('A2:Z2')[0];
        $this->assertStringStartsWith('PAC-', $dataRow[0]);
    }

    public function test_download_deletes_file_after_serving(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $this->createAdmission();

        $token = $this->postJson('/api/admin/exports', ['pseudonymized' => true])->json('download_token');
        $path = config('neurologia.exports_path').DIRECTORY_SEPARATOR.$token;
        $this->generatedFiles[] = $path;

        $this->assertFileExists($path);

        $response = $this->get("/api/admin/exports/{$token}/download")->assertOk();

        // TestResponse não passa pelo ciclo real de ->send(), então
        // BinaryFileResponse::sendContent() (onde o unlink acontece de fato,
        // ver Symfony\HttpFoundation\BinaryFileResponse) nunca dispara nos
        // testes de integração do Laravel. Disparamos manualmente aqui para
        // provar que a exclusão pós-download realmente acontece em produção.
        ob_start();
        $response->baseResponse->sendContent();
        ob_end_clean();

        $this->assertFileDoesNotExist($path);
    }

    public function test_download_rejects_path_traversal(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->get('/api/admin/exports/'.urlencode('../../../.env').'/download')->assertStatus(404);
    }
}
