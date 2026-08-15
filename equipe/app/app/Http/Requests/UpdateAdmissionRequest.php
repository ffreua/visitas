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

            'hospital_discharge_at' => ['nullable', 'date', 'after_or_equal:admission_at'],

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
