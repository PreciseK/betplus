<?php

declare(strict_types=1);

namespace App\Http\Requests\BackOffice;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCrashConfigDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'string', 'max:30'],
            'house_edge_basis_points' => ['required', 'integer', 'min:0', 'max:10000'],
            'betting_window_seconds' => ['required', 'integer', 'min:1'],
            'post_crash_interval_seconds' => ['required', 'integer', 'min:1'],
            'growth_rate_constant' => ['required', 'integer', 'min:1'],
            'effective_at' => ['required', 'date'],
            'actuarial_cert_ref' => ['nullable', 'string', 'max:100'],
        ];
    }
}
