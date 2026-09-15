<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class DirectWithdrawOpayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'amount_kobo' => ['required', 'integer', 'min:1'],
            // Required (not optional, unlike FundingService's own default-to-ULID
            // fallback) so a client retry always reuses the same reference and
            // FundingService::directWithdrawFromOpay's own dedup check
            // (Collection::where('reference', ...)) actually catches it, instead of
            // minting a fresh Collection — and a fresh credit — on every retry.
            'reference' => ['required', 'string', 'min:8', 'max:64'],
        ];
    }
}
