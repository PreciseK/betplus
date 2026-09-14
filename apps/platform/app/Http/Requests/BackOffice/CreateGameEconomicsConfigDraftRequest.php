<?php

declare(strict_types=1);

namespace App\Http\Requests\BackOffice;

use Illuminate\Foundation\Http\FormRequest;

class CreateGameEconomicsConfigDraftRequest extends FormRequest
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
            'version' => ['required', 'string', 'max:30'],
            'active_model' => ['required', 'string', 'in:FIXED_RTP,BALANCED_HYBRID,DAILY_LOSS_STOP,PARI_MUTUEL_POOL'],
            'params' => ['nullable', 'array'],
            'effective_at' => ['required', 'date'],
        ];
    }
}
