<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePatientRequest;
use App\Http\Requests\UpdatePatientRequest;
use App\Models\Admission;
use App\Models\Patient;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PatientController extends Controller
{
    /**
     * Busca o paciente pelo número de prontuário OU pelo número de
     * atendimento (fluxo de reinternação, seção 25 do PRD). O prontuário
     * identifica a pessoa e é estável; o atendimento identifica a passagem
     * e muda de uma internação para outra — por isso a busca por
     * atendimento resolve via episódio e devolve o paciente dele.
     *
     * Nunca cria paciente automaticamente — o frontend decide cadastrar se 404.
     */
    public function lookup(Request $request)
    {
        $this->authorize('viewAny', Patient::class);

        $data = $request->validate([
            'medical_record_number' => ['nullable', 'string'],
            'attendance_number' => ['nullable', 'string'],
        ]);

        $mrn = Patient::normalizeMedicalRecordNumber($data['medical_record_number'] ?? null);
        $attendance = Admission::normalizeAttendanceNumber($data['attendance_number'] ?? null);

        if ($mrn === null && $attendance === null) {
            throw ValidationException::withMessages([
                'medical_record_number' => 'Informe o número de prontuário ou o número de atendimento.',
            ]);
        }

        $patient = null;
        $matchedAdmission = null;

        if ($mrn !== null) {
            $patient = Patient::where('medical_record_number', $mrn)->first();
        }

        if (! $patient && $attendance !== null) {
            // withTrashed: um número de atendimento cujo episódio foi
            // excluído por engano ainda deve reencontrar o paciente, em vez
            // de mandar o usuário recadastrar alguém que já existe.
            $matchedAdmission = Admission::withTrashed()
                ->where('attendance_number', $attendance)
                ->latest('admission_at')
                ->first();

            $patient = $matchedAdmission?->patient;
        }

        if (! $patient) {
            return response()->json(['message' => 'Paciente não encontrado'], 404);
        }

        return response()->json([
            'patient' => $patient,
            'matched_by' => $matchedAdmission ? 'ATTENDANCE_NUMBER' : 'MEDICAL_RECORD_NUMBER',
            'matched_admission' => $matchedAdmission,
            'previously_followed' => $patient->admissions()->count() > 0,
            'admissions_count' => $patient->admissions()->count(),
            'active_admission' => $patient->activeAdmission(),
            'last_admission' => $patient->admissions()->latest('admission_at')->first(),
        ]);
    }

    public function store(StorePatientRequest $request)
    {
        $this->authorize('create', Patient::class);

        $data = $request->validated();
        $normalized = Patient::normalizeMedicalRecordNumber($data['medical_record_number'] ?? null);

        if ($normalized !== null && Patient::where('medical_record_number', $normalized)->exists()) {
            throw ValidationException::withMessages([
                'medical_record_number' => 'Já existe paciente cadastrado com este número de prontuário.',
            ]);
        }

        $patient = Patient::create([
            'medical_record_number' => $normalized,
            'full_name' => $data['full_name'],
            'date_of_birth' => $data['date_of_birth'] ?? null,
        ]);

        // Recarrega para que colunas nunca atribuídas em memória (ex.:
        // medical_record_confirmed_at quando o paciente entra só pelo
        // número de atendimento) apareçam no JSON — mesmo motivo do
        // refresh() em AdmissionController::store.
        $patient->refresh();

        AuditLogger::logModel('CREATE_PATIENT', $patient);

        return response()->json(['patient' => $patient], 201);
    }

    /**
     * Edição do cadastro. Prontuário confirmado é imutável; enquanto não
     * houver prontuário, ele pode ser preenchido uma única vez (e a
     * confirmação já vem exigida pela request).
     */
    public function update(UpdatePatientRequest $request, Patient $patient)
    {
        $this->authorize('update', $patient);

        $data = $request->validated();

        foreach (['full_name', 'date_of_birth'] as $field) {
            if (array_key_exists($field, $data)) {
                $patient->{$field} = $data[$field];
            }
        }

        if (array_key_exists('medical_record_number', $data) && ! $patient->hasConfirmedMedicalRecord()) {
            $normalized = Patient::normalizeMedicalRecordNumber($data['medical_record_number']);

            if ($normalized !== null) {
                $exists = Patient::where('medical_record_number', $normalized)
                    ->whereKeyNot($patient->getKey())
                    ->exists();

                if ($exists) {
                    throw ValidationException::withMessages([
                        'medical_record_number' => 'Já existe paciente cadastrado com este número de prontuário.',
                    ]);
                }

                // medical_record_confirmed_at é preenchido pelo model.
                $patient->medical_record_number = $normalized;
            }
        }

        $changed = array_keys($patient->getDirty());

        if ($changed !== []) {
            $patient->save();
            AuditLogger::logModel('UPDATE_PATIENT', $patient, $changed);
        }

        return response()->json(['patient' => $patient->fresh()]);
    }

    public function history(Patient $patient)
    {
        $this->authorize('view', $patient);

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
