<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // O paciente entra pelo prontuário OU pelo número de atendimento
            // — nunca sem nenhum dos dois, senão o registro nasce sem
            // qualquer identificador do sistema do hospital.
            'medical_record_number' => [
                Rule::requiredIf(fn () => blank($this->input('attendance_number'))),
                'nullable', 'string', 'max:50',
            ],

            // Só acompanha a validação (garante que existe identificador);
            // quem persiste o número de atendimento é o episódio.
            'attendance_number' => ['nullable', 'string', 'max:50'],

            // Prontuário só é gravado sob confirmação explícita, porque
            // depois disso vira imutável (é o índice dos dados de gestão).
            // A regra é montada condicionalmente, não com requiredIf +
            // nullable: "accepted" é regra implícita no Laravel (roda mesmo
            // com o campo ausente) e nullable não a suprime — o cadastro
            // por número de atendimento seria rejeitado sem prontuário
            // nenhum em jogo.
            'medical_record_confirmed' => filled($this->input('medical_record_number'))
                ? ['required', 'accepted']
                : ['nullable'],

            'full_name' => ['required', 'string', 'max:255'],

            // Opcional no cadastro (nem sempre se tem à mão na porta da
            // enfermaria) e cobrada no encerramento, junto com o prontuário
            // — ver AdmissionController::close.
            'date_of_birth' => ['nullable', 'date', 'before:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'medical_record_number.required' => 'Informe o número de prontuário ou o número de atendimento.',
            'medical_record_confirmed.required' => 'Confirme o número de prontuário — depois de confirmado ele não poderá mais ser alterado.',
            'medical_record_confirmed.accepted' => 'Confirme o número de prontuário — depois de confirmado ele não poderá mais ser alterado.',
        ];
    }
}
