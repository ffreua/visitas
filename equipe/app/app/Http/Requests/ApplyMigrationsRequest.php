<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Atualizar a estrutura do banco pela web é uma operação de manutenção,
 * não de rotina — a hospedagem do serviço não tem terminal, então a
 * alternativa seria não ter caminho nenhum. Reautenticação + frase de
 * confirmação são o mesmo padrão da Zona de Perigo.
 */
class ApplyMigrationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
            'confirmation_phrase' => ['required', 'string', 'in:ATUALIZAR BANCO'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirmation_phrase.in' => 'Digite exatamente "ATUALIZAR BANCO" para confirmar.',
        ];
    }
}
