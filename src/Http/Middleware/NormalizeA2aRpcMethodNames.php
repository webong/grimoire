<?php

namespace Webong\Gouge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizeA2aRpcMethodNames
{
    /**
     * @var array<string, string>
     */
    protected array $canonicalToSlash = [
        'SendMessage' => 'message/send',
        'SendStreamingMessage' => 'message/stream',
        'GetTask' => 'tasks/get',
        'ListTask' => 'tasks/list',
        'ListTasks' => 'tasks/list',
        'CancelTask' => 'tasks/cancel',
        'TaskResubscription' => 'tasks/resubscribe',
        'CreateTaskPushNotificationConfig' => 'tasks/pushNotificationConfig/create',
        'SetTaskPushNotificationConfig' => 'tasks/pushNotificationConfig/set',
        'GetTaskPushNotificationConfig' => 'tasks/pushNotificationConfig/get',
        'ListTaskPushNotificationConfig' => 'tasks/pushNotificationConfig/list',
        'DeleteTaskPushNotificationConfig' => 'tasks/pushNotificationConfig/delete',
    ];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $raw = $request->getContent();

        if (! is_string($raw) || trim($raw) === '') {
            return $next($request);
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return $next($request);
        }

        $normalized = $this->normalizePayload($decoded);
        $encoded = json_encode($normalized, JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded) || $encoded === '') {
            return $next($request);
        }

        $request->initialize(
            $request->query->all(),
            $request->request->all(),
            $request->attributes->all(),
            $request->cookies->all(),
            $request->files->all(),
            $request->server->all(),
            $encoded,
        );

        return $next($request);
    }

    /**
     * @param  array<int|string, mixed>  $payload
     * @return array<int|string, mixed>
     */
    protected function normalizePayload(array $payload): array
    {
        if (array_is_list($payload)) {
            return collect($payload)
                ->map(function (mixed $item): mixed {
                    if (! is_array($item)) {
                        return $item;
                    }

                    $item['method'] = $this->normalizeMethod($item['method'] ?? null);

                    return $item;
                })
                ->values()
                ->all();
        }

        $payload['method'] = $this->normalizeMethod($payload['method'] ?? null);

        return $payload;
    }

    protected function normalizeMethod(mixed $method): mixed
    {
        if (! is_string($method)) {
            return $method;
        }

        return $this->canonicalToSlash[$method] ?? $method;
    }
}
