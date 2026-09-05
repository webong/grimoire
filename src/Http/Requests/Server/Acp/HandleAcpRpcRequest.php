<?php

namespace Webong\LaravelGouge\Http\Requests\Server\Acp;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class HandleAcpRpcRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'jsonrpc' => ['required', 'string', 'in:2.0'],
            'id' => ['nullable'],
            'method' => ['required', 'string', 'max:255'],
            'params' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'jsonrpc.required' => 'The JSON-RPC version is required.',
            'jsonrpc.in' => 'Only JSON-RPC 2.0 is supported.',
            'method.required' => 'An ACP method is required.',
            'params.array' => 'ACP method parameters must be an object.',
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json([
            'jsonrpc' => '2.0',
            'id' => $this->input('id'),
            'error' => [
                'code' => -32600,
                'message' => 'Invalid Request.',
                'data' => [
                    'errors' => $validator->errors()->toArray(),
                ],
            ],
        ]));
    }
}
