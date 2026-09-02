<?php

namespace App\Http\Requests;

use App\Models\Patient;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Edição do cadastro do paciente. Nome e nascimento são sempre editáveis
 * (erro de digitação acontece); o prontuário só pode ser PREENCHIDO uma
 * vez — se já está confirmado, qualquer tentativa de troca é rejeitada
 * aqui e, em última instância, no próprio model.
 */
class UpdatePatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $patient = $this->route('patient');

        // Ver StorePatientRequest: "accepted" é regra implícita, então a
        // confirmação só pode entrar na lista quando de fato se aplica.
        $needsConfirmation = filled($this->input('medical_record_number'))
            && $patient && ! $patient->hasConfirmedMedicalRecord();

        return [
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],

            'medical_record_number' => [
                'sometimes', 'nullable', 'string', 'max:50',
                function ($attribute, $value, $fail) use ($patient) {
                    if ($patient && $patient->hasConfirmedMedicalRecord()
                        && Patient::normalizeMedicalRecordNumber($value) !== $patient->medical_record_number) {
                        $fail('O número de prontuário já foi confirmado e não pode mais ser alterado.');
                    }
                },
            ],

            'medical_record_confirmed' => $needsConfirmation
                ? ['required', 'accepted']
                : ['nullable'],
        ];
    }

    public function messages(): array
    {
        return [
            'medical_record_confirmed.required' => 'Confirme o número de prontuário — depois de confirmado ele não poderá mais ser alterado.',
            'medical_record_confirmed.accepted' => 'Confirme o número de prontuário — depois de confirmado ele não poderá mais ser alterado.',
        ];
    }
}
