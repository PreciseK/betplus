<?php

declare(strict_types=1);

namespace App\Http\Requests\BackOffice;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Same shape as CreatePrizeTableDraftRequest — an edit replaces the full tier set
 * rather than patching individual fields, so drafts stay easy to reason about and
 * the publication gate always sees a complete, consistent table.
 */
class UpdatePrizeTableDraftRequest extends FormRequest
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
