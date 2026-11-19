<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAiCredentialRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // A listed model or any other the provider offers; blank uses its default.
            'model' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/'],
            // Blank keeps the saved key.
            'api_key' => ['nullable', 'string', 'min:8', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['model.regex' => 'Enter the model ID exactly as the provider names it, e.g. gemini-3-flash-preview.'];
    }
}
