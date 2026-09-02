<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mesma reautenticação das demais operações irreversíveis (zona de perigo,
 * exclusão definitiva de episódio, exclusão de backup).
 */
class DeleteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
        ];
    }
}
