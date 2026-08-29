<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class RefreshTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            // The browser flow never puts the HttpOnly refresh_token cookie in the body —
            // SessionController::refresh() falls back to the cookie when this is absent.
            'refresh_token' => ['sometimes', 'string'],
        ];
    }
}
