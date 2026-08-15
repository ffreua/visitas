<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResetClinicalDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
            'confirmation_phrase' => ['required', 'string', 'in:ZERAR DADOS CLINICOS'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirmation_phrase.in' => 'Digite exatamente "ZERAR DADOS CLINICOS" para confirmar.',
        ];
    }
}
