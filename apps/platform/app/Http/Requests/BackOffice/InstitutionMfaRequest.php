<?php

declare(strict_types=1);

namespace App\Http\Requests\BackOffice;

use Illuminate\Foundation\Http\FormRequest;

class InstitutionMfaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'challenge_id' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ];
    }
}
