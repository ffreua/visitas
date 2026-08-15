<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CloseAdmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer'],
            'final_cid_code' => ['required', 'string', 'exists:cid10,code'],
            'discharge_outcome' => ['required', 'string'],
            'followup_plan_documented' => ['nullable', 'string'],
            'neurology_followup_closed_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'final_cid_code.required' => 'Diagnóstico final é obrigatório para encerrar o acompanhamento.',
            'discharge_outcome.required' => 'Desfecho é obrigatório para encerrar o acompanhamento.',
        ];
    }
}
