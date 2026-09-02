<?php

namespace App\Http\Requests;

use App\Models\Admission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer'],

            // Editável (muda de uma internação para outra e é digitado à
            // mão), mas não pode ser apagado quando é o único identificador
            // do episódio — paciente ainda sem prontuário confirmado.
            'attendance_number' => [
                Rule::requiredIf(function () {
                    $admission = $this->route('admission');

                    return $admission && $admission->patient && ! $admission->patient->hasConfirmedMedicalRecord();
                }),
                'nullable', 'string', 'max:50',
            ],

            // A alta hospitalar é registrada no encerramento
            // (CloseAdmissionRequest) e não é editável no fluxo normal.
            // ADMIN pode corrigi-la depois — erro de digitação num campo
            // definitivo precisa ter conserto, mas não por qualquer um.
            'hospital_discharge_at' => [
                Rule::prohibitedIf(fn () => ! $this->user()?->isAdmin()),
                'nullable', 'date',
                function ($attribute, $value, $fail) {
                    $admission = $this->route('admission');

                    if ($admission && $value && strtotime($value) < strtotime($admission->admission_at)) {
                        $fail('A alta hospitalar não pode ser anterior à data de entrada.');
                    }
                },
            ],

            'payer_type' => ['sometimes', Rule::in(['HEALTH_PLAN', 'PRIVATE'])],
            'health_plan_id' => [
                Rule::requiredIf(fn () => $this->input('payer_type') === 'HEALTH_PLAN'),
                Rule::prohibitedIf(fn () => $this->input('payer_type') === 'PRIVATE'),
                'nullable', 'integer', 'exists:health_plans,id',
            ],

            'origin' => ['sometimes', 'required', Rule::in(array_keys(Admission::ORIGINS))],
            'unit' => ['nullable', 'string', 'max:255'],
            'bed' => ['nullable', 'string', 'max:255'],

            'requesting_specialty_id' => ['nullable', 'integer', 'exists:medical_specialties,id'],
            'consult_reason' => ['nullable', 'string'],
            'consult_priority' => ['nullable', 'string', 'max:50'],
            'consult_requested_at' => ['nullable', 'date'],

            'brief_history' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'attendance_number.required' => 'Número de atendimento é obrigatório enquanto o paciente não tiver prontuário confirmado.',
            'hospital_discharge_at.prohibited' => 'A alta hospitalar é registrada no encerramento. Só um administrador pode corrigi-la depois.',
            'origin.in' => 'Procedência inválida.',
        ];
    }
}
