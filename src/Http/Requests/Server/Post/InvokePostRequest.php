<?php

namespace Webong\LaravelGouge\Http\Requests\Server\Post;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class InvokePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'instructions' => ['required', 'string', 'max:20000'],
            'channel_id' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'instructions.required' => 'Instructions are required to invoke a post.',
            'instructions.max' => 'Instructions may not exceed 20,000 characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $instructions = $this->input('instructions');

        if (is_string($instructions)) {
            $this->merge([
                'instructions' => trim($instructions),
            ]);
        }
    }
}
