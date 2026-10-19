<?php

namespace Webong\Gouge\Http\Requests\Server\A2a;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class HandleA2aRpcRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'jsonrpc' => ['required', 'string', 'in:2.0'],
            'method' => ['required', 'string', 'in:message/send,message/stream,tasks/get,tasks/list,tasks/cancel,tasks/resubscribe,SendMessage,SendStreamingMessage,GetTask,ListTask,ListTasks,CancelTask,TaskResubscription,CreateTaskPushNotificationConfig,SetTaskPushNotificationConfig,GetTaskPushNotificationConfig,ListTaskPushNotificationConfig,DeleteTaskPushNotificationConfig'],
            'params' => ['nullable', 'array'],
            'id' => ['nullable'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'jsonrpc.required' => 'The jsonrpc field is required.',
            'jsonrpc.in' => 'Only JSON-RPC 2.0 is supported.',
            'method.required' => 'An A2A method is required.',
            'method.in' => 'The selected A2A method is not supported.',
            'params.array' => 'The params field must be an object.',
        ];
    }
}
