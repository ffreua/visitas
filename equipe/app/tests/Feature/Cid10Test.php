<?php

namespace Tests\Feature;

use App\Models\CID10;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Cid10Test extends TestCase
{
    use RefreshDatabase;

    public function test_cid10_autocomplete_search(): void
    {
        $this->actingAs(User::factory()->create());

        CID10::create([
            'code' => 'G40.9',
            'description' => 'Epilepsia, não especificada',
            'category' => 'G40',
            'normalized_description' => 'epilepsia, nao especificada',
        ]);

        $response = $this->getJson('/api/cid10/search?q=epi')->assertOk()->json();
        $this->assertCount(1, $response);
        $this->assertSame('G40.9', $response[0]['code']);

        $responseCode = $this->getJson('/api/cid10/search?q=G40')->assertOk()->json();
        $this->assertCount(1, $responseCode);
    }

    public function test_cid10_import_command_supports_json(): void
    {
        $tempJson = tempnam(sys_get_temp_dir(), 'cid').'.json';
        file_put_contents($tempJson, json_encode([
            ['A00' => 'A00.0', 'Cólera' => 'Cólera teste'],
            ['A00' => 'G40.1', 'Cólera' => 'Epilepsia focal teste'],
        ]));

        $this->artisan('cid10:import', ['file' => $tempJson])->assertSuccessful();

        $this->assertDatabaseHas('cid10', ['code' => 'A00.0', 'description' => 'Cólera teste']);
        $this->assertDatabaseHas('cid10', ['code' => 'G40.1', 'description' => 'Epilepsia focal teste']);

        @unlink($tempJson);
    }
}
