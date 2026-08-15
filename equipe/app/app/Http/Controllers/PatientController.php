<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePatientRequest;
use App\Models\Patient;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PatientController extends Controller
{
    /**
     * Busca por número de prontuário (fluxo de reinternação, seção 25 do PRD).
     * Nunca cria paciente automaticamente — o frontend decide cadastrar se 404.
     */
    public function lookup(Request $request)
    {
        $request->validate(['medical_record_number' => ['required', 'string']]);

        $normalized = Patient::normalizeMedicalRecordNumber($request->string('medical_record_number'));

        $patient = Patient::where('medical_record_number', $normalized)->first();

        if (! $patient) {
            return response()->json(['message' => 'Paciente não encontrado'], 404);
        }

        $activeAdmission = $patient->activeAdmission();
        $lastAdmission = $patient->admissions()->latest('admission_at')->first();

        return response()->json([
            'patient' => $patient,
            'previously_followed' => $patient->admissions()->count() > 0,
            'admissions_count' => $patient->admissions()->count(),
            'active_admission' => $activeAdmission,
            'last_admission' => $lastAdmission,
        ]);
    }

    public function store(StorePatientRequest $request)
    {
        $normalized = Patient::normalizeMedicalRecordNumber($request->string('medical_record_number'));

        if (Patient::where('medical_record_number', $normalized)->exists()) {
            throw ValidationException::withMessages([
                'medical_record_number' => 'Já existe paciente cadastrado com este número de prontuário.',
            ]);
        }

        $patient = Patient::create($request->validated());

        AuditLogger::logModel('CREATE_PATIENT', $patient);

        return response()->json(['patient' => $patient], 201);
    }

    public function history(Patient $patient)
    {
        $admissions = $patient->admissions()
            ->with(['healthPlan', 'requestingSpecialty', 'diagnoses'])
            ->orderByDesc('admission_at')
            ->paginate(15);

        return response()->json([
            'patient' => $patient,
            'admissions' => $admissions,
        ]);
    }
}
