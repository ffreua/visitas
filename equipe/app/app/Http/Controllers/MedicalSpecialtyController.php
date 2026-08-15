<?php

namespace App\Http\Controllers;

use App\Models\MedicalSpecialty;
use Illuminate\Http\Request;

class MedicalSpecialtyController extends Controller
{
    public function search(Request $request)
    {
        $term = $request->string('q')->toString();

        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        return response()->json(
            MedicalSpecialty::active()->search($term)->orderBy('name')->limit(10)->get(['id', 'name'])
        );
    }

    public function index()
    {
        $this->authorize('viewAny', MedicalSpecialty::class);

        return response()->json(MedicalSpecialty::orderBy('name')->paginate(30));
    }

    public function store(Request $request)
    {
        $this->authorize('create', MedicalSpecialty::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $specialty = MedicalSpecialty::create([...$data, 'active' => true]);

        return response()->json($specialty, 201);
    }

    public function update(Request $request, MedicalSpecialty $medicalSpecialty)
    {
        $this->authorize('update', $medicalSpecialty);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $medicalSpecialty->update($data);

        return response()->json($medicalSpecialty);
    }
}
