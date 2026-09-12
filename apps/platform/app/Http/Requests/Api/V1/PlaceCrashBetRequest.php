<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class PlaceCrashBetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'stake_kobo' => ['required', 'integer', 'min:1'],
            'auto_cashout_multiplier_hundredths' => ['nullable', 'integer', 'min:200'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }
}
