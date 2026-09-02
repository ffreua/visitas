<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Backup é a rede de segurança do banco: apagar um é irreversível e não
 * pode depender só de uma sessão aberta por engano. Mesma reautenticação
 * exigida na Zona de Perigo e na exclusão definitiva de episódio.
 */
class DeleteBackupRequest extends FormRequest
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
