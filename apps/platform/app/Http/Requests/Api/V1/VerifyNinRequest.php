<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class VerifyNinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'date_of_birth' => ['required', 'date', 'before:today'],
            'nin' => ['required', 'digits:11'],
        ];
    }
}
