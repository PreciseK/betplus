<?php

declare(strict_types=1);

namespace App\Http\Requests\BackOffice;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateInstitutionUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255', 'unique:institutionUser,email'],
            'display_name' => ['required', 'string', 'max:100'],
            'role' => ['required', Rule::in([
                'support_agent', 'support_lead', 'finance', 'compliance', 'game_ops', 'content_editor', 'cultural_reviewer', 'system_admin',
            ])],
        ];
    }
}
