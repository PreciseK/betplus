<?php

declare(strict_types=1);

namespace App\Http\Requests\BackOffice;

use Illuminate\Foundation\Http\FormRequest;

class UpsertStateLicenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'state_code' => ['required', 'string', 'max:10'],
            'licence_number' => ['required', 'string', 'max:100'],
            'issued_at' => ['required', 'date'],
            'expires_at' => ['required', 'date', 'after:issued_at'],
            'ruleset_version' => ['required', 'string', 'max:30'],
            'remittance_status' => ['required', 'string', 'in:current,overdue,unknown'],
        ];
    }
}
