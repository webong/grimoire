<?php

declare(strict_types=1);

namespace Webong\LaravelGouge\Http\Procedures;

use Illuminate\Http\Request;
use Sajya\Server\Exceptions\RuntimeRpcException;
use Sajya\Server\Procedure;
use Webong\LaravelGouge\Ai\Support\A2a\A2aMethodRouter;

class A2aProcedure extends Procedure
{
    /**
     * The name of the procedure that is used for referencing.
     */
    public static string $name = 'message';

    public function send(Request $request, A2aMethodRouter $router): array
    {
        return $this->resolve($router, 'message/send', $request);
    }

    public function stream(Request $request, A2aMethodRouter $router): array
    {
        return $this->resolve($router, 'message/stream', $request);
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
