<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ForceDeleteAdmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:500'],
            'confirmation_phrase' => ['required', 'string', 'in:EXCLUIR DEFINITIVAMENTE'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirmation_phrase.in' => 'Digite exatamente "EXCLUIR DEFINITIVAMENTE" para confirmar.',
        ];
    }
}
