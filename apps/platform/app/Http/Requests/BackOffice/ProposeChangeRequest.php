<?php

declare(strict_types=1);

namespace App\Http\Requests\BackOffice;

use Illuminate\Foundation\Http\FormRequest;

class ProposeChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'change_type' => ['required', 'string'],
            'payload' => ['required', 'array'],
            'justification' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
