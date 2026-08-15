<?php

namespace App\Http\Controllers;

use App\Models\HealthPlan;
use Illuminate\Http\Request;

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
}
