<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseHeritageTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // REQ-HG-003 — exactly 5, distinct, and 'distinct' below catches duplicates
            // that 'array'+count alone would miss.
            'selected_positions' => ['required', 'array', 'size:5', 'distinct'],
            'selected_positions.*' => ['required', 'integer', 'min:0', 'max:8'],
            'stake_kobo' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            // REQ-HG-050 — optional per-purchase override; defaults to the player's
            // stored profile preference (or the first configured tradition) if omitted.
            'tradition' => ['nullable', 'string', Rule::in(array_keys((array) config('heritage.traditions')))],
            'leader_type' => ['nullable', 'string', 'in:king,queen'],
        ];
    }
}
