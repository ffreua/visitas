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

            // Único momento em que a alta hospitalar é gravada. Opcional
            // porque a Neurologia pode encerrar o acompanhamento com o
            // paciente ainda internado sob outra equipe — nesse caso o
            // campo fica em branco em vez de receber uma data inventada.
            'hospital_discharge_at' => ['nullable', 'date', function ($attribute, $value, $fail) {
                $admission = $this->route('admission');

                if ($admission && $value && strtotime($value) < strtotime($admission->admission_at)) {
                    $fail('A alta hospitalar não pode ser anterior à data de entrada.');
                }
            }],
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
