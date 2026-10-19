<?php

namespace Webong\Gouge\Http\Requests\Server\Acp;

use Illuminate\Foundation\Http\FormRequest;

class PromptAcpSessionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'space_uuid' => $this->trimmedString(
                $this->input('space_uuid')
                ?? $this->input('space_id')
                ?? $this->input('spaceId')
                ?? $this->input('workspaceId')
            ),
            'text' => $this->trimmedString(
                $this->input('text')
                ?? $this->input('prompt')
                ?? $this->input('input')
            ),
        ]);
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'space_uuid' => ['nullable', 'uuid', 'exists:spaces,uuid'],
            'text' => ['required', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'space_uuid.exists' => 'The selected space was not found.',
            'text.required' => 'A text prompt is required.',
        ];
    }

    protected function trimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
