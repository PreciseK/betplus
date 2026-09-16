<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class CreateDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'quote_id' => ['required', 'string'],
            // Required (not optional) so a client retry always reuses the same
            // reference and FundingService::collect()'s own dedup check
            // (Collection::where('reference', ...)) actually catches it.
            'reference' => ['required', 'string', 'min:8', 'max:64'],
        ];
    }
}
