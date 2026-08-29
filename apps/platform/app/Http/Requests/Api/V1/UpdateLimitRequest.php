<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLimitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'in:deposit-daily,deposit-weekly,deposit-monthly,stake-daily,stake-weekly,session-time'],
            'value' => ['required', 'integer', 'min:1'],
        ];
    }
}
