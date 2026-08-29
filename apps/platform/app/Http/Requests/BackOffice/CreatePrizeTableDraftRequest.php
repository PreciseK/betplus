<?php

declare(strict_types=1);

namespace App\Http\Requests\BackOffice;

use Illuminate\Foundation\Http\FormRequest;

class CreatePrizeTableDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'game_code' => ['required', 'string', 'max:20'],
            'state_code' => ['nullable', 'string', 'max:10'],
            'version' => ['required', 'string', 'max:30'],
            'effective_at' => ['required', 'date'],
            'actuarial_cert_ref' => ['nullable', 'string', 'max:100'],
            'tiers' => ['required', 'array', 'min:1'],
            'tiers.*.positions' => ['required', 'integer', 'min:1'],
            'tiers.*.multiplier_hundredths' => ['required', 'integer', 'min:1'],
            'tiers.*.probability_numerator' => ['required', 'integer', 'min:1'],
            'tiers.*.probability_denominator' => ['required', 'integer', 'min:1'],
        ];
    }
}
