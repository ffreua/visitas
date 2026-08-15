<?php

namespace Tests\Feature;

use App\Models\HealthPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class HealthPlanImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_physician_cannot_import(): void
    {
        $this->actingAs(User::factory()->create());

        $file = UploadedFile::fake()->createWithContent('planos.csv', "name\nAmil\n");

        $this->postJson('/api/admin/health-plans/import', ['file' => $file])->assertForbidden();
    }

    public function test_admin_imports_new_plans_and_skips_existing(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        HealthPlan::create(['name' => 'Amil', 'normalized_name' => 'amil', 'active' => true]);

        $csv = "name\nAmil\nSulAmérica\nPorto Saúde\n";
        $file = UploadedFile::fake()->createWithContent('planos.csv', $csv);

        $response = $this->postJson('/api/admin/health-plans/import', ['file' => $file])->assertOk();

        $this->assertSame(2, $response->json('created'));
        $this->assertSame(1, $response->json('skipped_existing'));
        $this->assertDatabaseHas('health_plans', ['name' => 'SulAmérica']);
        $this->assertDatabaseHas('health_plans', ['name' => 'Porto Saúde']);
        $this->assertDatabaseCount('health_plans', 3);
    }

    public function test_rejects_csv_without_name_column(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $file = UploadedFile::fake()->createWithContent('planos.csv', "descricao\nAmil\n");

        $this->postJson('/api/admin/health-plans/import', ['file' => $file])->assertStatus(422);
    }
}
