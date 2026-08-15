<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportHealthPlansRequest;
use App\Models\HealthPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HealthPlanController extends Controller
{
    /**
     * Autocomplete: case-insensitive, accent-insensitive, limitado a 10.
     */
    public function search(Request $request)
    {
        $term = $request->string('q')->toString();

        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        return response()->json(
            HealthPlan::active()->search($term)->orderBy('name')->limit(10)->get(['id', 'name'])
        );
    }

    public function index()
    {
        $this->authorize('viewAny', HealthPlan::class);

        return response()->json(HealthPlan::orderBy('name')->paginate(30));
    }

    public function store(Request $request)
    {
        $this->authorize('create', HealthPlan::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'aliases' => ['nullable', 'array'],
        ]);

        $plan = HealthPlan::create([...$data, 'active' => true]);

        return response()->json($plan, 201);
    }

    public function update(Request $request, HealthPlan $healthPlan)
    {
        $this->authorize('update', $healthPlan);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'aliases' => ['nullable', 'array'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $healthPlan->update($data);

        return response()->json($healthPlan);
    }

    /**
     * Importação em massa via CSV (seção 16 do PRD, opcional). Espera
     * cabeçalho com pelo menos a coluna "name"; nunca apaga planos
     * existentes, apenas cria os que ainda não existem (por nome
     * normalizado) — nomes duplicados no arquivo ou já cadastrados são
     * simplesmente ignorados, não geram erro.
     */
    public function import(ImportHealthPlansRequest $request)
    {
        $this->authorize('create', HealthPlan::class);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = fgetcsv($handle);

        if (! $header || ! in_array('name', array_map('trim', $header), true)) {
            fclose($handle);

            return response()->json(['message' => 'CSV deve conter ao menos a coluna "name".'], 422);
        }

        $header = array_map('trim', $header);
        $created = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $record = array_combine($header, $row);
            $name = trim($record['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $normalized = Str::of($name)->ascii()->lower()->toString();

            $plan = HealthPlan::firstOrNew(['normalized_name' => $normalized]);
            if ($plan->exists) {
                $skipped++;

                continue;
            }

            $plan->name = $name;
            $plan->normalized_name = $normalized;
            $plan->active = true;
            $plan->save();
            $created++;
        }

        fclose($handle);

        return response()->json(['created' => $created, 'skipped_existing' => $skipped]);
    }
}
