<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DashboardFilterRequest extends FormRequest
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
            'include_deleted' => ['nullable', 'boolean'],
        ];
    }
}
