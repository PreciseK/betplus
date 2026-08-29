<?php

declare(strict_types=1);

namespace App\Http\Requests\BackOffice;

use Illuminate\Foundation\Http\FormRequest;

class RequestFinancialReportExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'game_code' => ['nullable', 'string', 'max:20'],
            'state_code' => ['nullable', 'string', 'max:10'],
        ];
    }
}
