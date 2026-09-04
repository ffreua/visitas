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
            // Sem isto, inverter as datas devolvia zero em silêncio — o
            // relatório vinha vazio e parecia "não houve movimento".
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'period_mode' => ['nullable', 'in:ADMISSION,OVERLAP'],
            // Existia em AdmissionFilters e faltava aqui: validated() jogava
            // fora o valor, então o filtro por situação nunca chegava.
            'status' => ['nullable', 'in:ACTIVE,CLOSED'],
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

    public function messages(): array
    {
        return [
            'date_to.after_or_equal' => 'A data final não pode ser anterior à inicial.',
        ];
    }
}
