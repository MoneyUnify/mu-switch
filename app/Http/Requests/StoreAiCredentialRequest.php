<?php

namespace App\Http\Requests;

use App\Enums\AiProvider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAiCredentialRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'provider' => [
                'required',
                Rule::enum(AiProvider::class),
                Rule::unique('ai_credentials')->where('user_id', $this->user()->id),
            ],
            // A listed model or any other the provider offers; blank uses its default.
            'model' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/'],
            // Blank uses the provider's key from .env.
            'api_key' => ['nullable', 'string', 'min:8', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provider.unique' => 'This provider is already in your list.',
            'model.regex' => 'Enter the model ID exactly as the provider names it, e.g. gemini-3-flash-preview.',
        ];
    }
}
