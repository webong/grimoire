<?php

namespace Webong\LaravelGouge\Http\Requests\Server\Acp;

use Illuminate\Foundation\Http\FormRequest;

class StoreAcpSessionRequest extends FormRequest
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
            'title' => $this->trimmedString($this->input('title') ?? $this->input('name')),
            'purpose' => $this->trimmedString($this->input('purpose')),
            'phase' => $this->trimmedString($this->input('phase')),
        ]);
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'space_uuid' => ['required', 'uuid', 'exists:spaces,uuid'],
            'title' => ['nullable', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:50'],
            'phase' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'space_uuid.required' => 'A space is required to create an ACP session.',
            'space_uuid.exists' => 'The selected space was not found.',
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
