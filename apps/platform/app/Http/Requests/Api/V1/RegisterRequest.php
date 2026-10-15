<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Identity\MobileNetwork;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'msisdn' => ['required', 'string', 'max:20'],
            'mobile_network' => ['nullable', Rule::enum(MobileNetwork::class)],
        ];
    }
}
