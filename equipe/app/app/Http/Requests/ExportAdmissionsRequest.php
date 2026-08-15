<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportAdmissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'care_type' => ['nullable', 'in:INSTITUTIONAL,INTERCONSULT'],
            'followup_mode' => ['nullable', 'in:ONGOING,SINGLE_EVALUATION'],
            'payer_type' => ['nullable', 'in:HEALTH_PLAN,PRIVATE'],
            'health_plan_id' => ['nullable', 'integer'],
            'requesting_specialty_id' => ['nullable', 'integer'],
            'physician_id' => ['nullable', 'integer'],
            'cid_code' => ['nullable', 'string'],
            'status' => ['nullable', 'in:ACTIVE,CLOSED'],
            'pseudonymized' => ['nullable', 'boolean'],
            // Reautenticação obrigatória apenas quando o export é identificável (seção 88).
            'password' => [Rule::requiredIf(fn () => ! $this->boolean('pseudonymized')), 'nullable', 'string'],
        ];
    }
}
