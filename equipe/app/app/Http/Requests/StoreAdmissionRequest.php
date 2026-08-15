<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'integer', 'exists:patients,id'],

            'admission_at' => ['required', 'date'],
            'hospital_discharge_at' => ['nullable', 'date', 'after_or_equal:admission_at'],

            'care_type' => ['required', Rule::in(['INSTITUTIONAL', 'INTERCONSULT'])],
            'followup_mode' => ['required', Rule::in(['ONGOING', 'SINGLE_EVALUATION'])],

            'payer_type' => ['required', Rule::in(['HEALTH_PLAN', 'PRIVATE'])],
            'health_plan_id' => [
                Rule::requiredIf(fn () => $this->input('payer_type') === 'HEALTH_PLAN'),
                Rule::prohibitedIf(fn () => $this->input('payer_type') === 'PRIVATE'),
                'nullable', 'integer', 'exists:health_plans,id',
            ],

            'origin' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:255'],
            'bed' => ['nullable', 'string', 'max:255'],

            'requesting_specialty_id' => [
                Rule::requiredIf(fn () => $this->input('care_type') === 'INTERCONSULT'),
                'nullable', 'integer', 'exists:medical_specialties,id',
            ],
            'consult_reason' => ['nullable', 'string'],
            'consult_priority' => ['nullable', 'string', 'max:50'],
            'consult_requested_at' => [
                Rule::requiredIf(fn () => $this->input('care_type') === 'INTERCONSULT'),
                'nullable', 'date',
            ],

            'brief_history' => ['nullable', 'string'],

            'suspected_cid_code' => ['required', 'string', 'exists:cid10,code'],
        ];
    }

    public function messages(): array
    {
        return [
            'health_plan_id.required' => 'Plano de saúde é obrigatório quando a forma de pagamento é convênio.',
            'health_plan_id.prohibited' => 'Não informe plano de saúde para atendimento particular.',
            'requesting_specialty_id.required' => 'Especialidade solicitante é obrigatória em interconsultas.',
            'consult_requested_at.required' => 'Horário da solicitação é obrigatório em interconsultas.',
            'suspected_cid_code.required' => 'Hipótese diagnóstica é obrigatória.',
        ];
    }
}
