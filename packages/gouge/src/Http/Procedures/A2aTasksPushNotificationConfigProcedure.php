<?php

declare(strict_types=1);

namespace Webong\Gouge\Http\Procedures;

use Illuminate\Http\Request;
use Sajya\Server\Exceptions\RuntimeRpcException;
use Sajya\Server\Procedure;
use Webong\Gouge\Ai\Support\A2a\A2aMethodRouter;

class A2aTasksPushNotificationConfigProcedure extends Procedure
{
    /**
     * The name of the procedure that is used for referencing.
     */
    public static string $name = 'tasks/pushNotificationConfig';

    public function set(Request $request, A2aMethodRouter $router): array
    {
        return $this->resolve($router, 'tasks/pushNotificationConfig/set', $request);
    }

    public function create(Request $request, A2aMethodRouter $router): array
    {
        return $this->resolve($router, 'tasks/pushNotificationConfig/create', $request);
    }

    public function get(Request $request, A2aMethodRouter $router): array
    {
        return $this->resolve($router, 'tasks/pushNotificationConfig/get', $request);
    }

    public function list(Request $request, A2aMethodRouter $router): array
    {
        return $this->resolve($router, 'tasks/pushNotificationConfig/list', $request);
    }

    public function delete(Request $request, A2aMethodRouter $router): array
    {
        return $this->resolve($router, 'tasks/pushNotificationConfig/delete', $request);
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolve(A2aMethodRouter $router, string $method, Request $request): array
    {
        $resolved = $router->handle($method, $request->all());

        if (is_array($resolved['error'] ?? null)) {
            throw (new RuntimeRpcException(
                (string) ($resolved['error']['message'] ?? 'Runtime error'),
                is_int($resolved['error']['code'] ?? null) ? $resolved['error']['code'] : -1,
            ))->setData(is_array($resolved['error']['data'] ?? null) ? $resolved['error']['data'] : null);
        }

        return is_array($resolved['result'] ?? null) ? $resolved['result'] : [];
    }
}
