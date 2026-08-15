<?php

namespace App\Http\Requests;

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

            // "admission_at" não é campo desta request (só existe no
            // StoreAdmissionRequest) — after_or_equal:admission_at aqui
            // seria sempre um no-op silencioso. Comparar contra o valor
            // já persistido no episódio sendo editado.
            'hospital_discharge_at' => ['nullable', 'date', function ($attribute, $value, $fail) {
                $admission = $this->route('admission');
                if ($admission && $value && strtotime($value) < strtotime($admission->admission_at)) {
                    $fail('A alta hospitalar não pode ser anterior à data de entrada.');
                }
            }],

            'payer_type' => ['sometimes', Rule::in(['HEALTH_PLAN', 'PRIVATE'])],
            'health_plan_id' => [
                Rule::requiredIf(fn () => $this->input('payer_type') === 'HEALTH_PLAN'),
                Rule::prohibitedIf(fn () => $this->input('payer_type') === 'PRIVATE'),
                'nullable', 'integer', 'exists:health_plans,id',
            ],

            'origin' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:255'],
            'bed' => ['nullable', 'string', 'max:255'],

            'requesting_specialty_id' => ['nullable', 'integer', 'exists:medical_specialties,id'],
            'consult_reason' => ['nullable', 'string'],
            'consult_priority' => ['nullable', 'string', 'max:50'],
            'consult_requested_at' => ['nullable', 'date'],

            'brief_history' => ['nullable', 'string'],
        ];
    }
}
