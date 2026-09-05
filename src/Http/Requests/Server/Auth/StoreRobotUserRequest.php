<?php

namespace Webong\LaravelGouge\Http\Requests\Server\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Webong\LaravelGouge\Support\AgentAbilities;

class StoreRobotUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->canActAsHuman();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255', 'unique:users,email'],
            'account_uuid' => ['nullable', 'string', 'uuid'],
            'token_name' => ['nullable', 'string', 'max:255'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string', Rule::in([
                ...array_keys(config('token-abilities', [])),
                ...AgentAbilities::values(),
            ])],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('abilities')) {
            $this->merge([
                'abilities' => AgentAbilities::defaults(),
            ]);
        }
    }
}
