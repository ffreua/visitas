<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SoftDeleteAdmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::in(['DUPLICATE', 'NOT_NEUROLOGY', 'CREATED_BY_MISTAKE', 'OTHER'])],
            'reason_detail' => ['required_if:reason,OTHER', 'nullable', 'string', 'max:500'],
        ];
    }
}
