<?php

declare(strict_types=1);

namespace App\Http\Requests\BackOffice;

use Illuminate\Foundation\Http\FormRequest;

class InstitutionSignInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}
