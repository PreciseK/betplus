<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class QuoteWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'source' => ['required', 'string', 'in:winnings,released-play'],
            'amount_kobo' => ['required', 'integer', 'min:1'],
        ];
    }
}
