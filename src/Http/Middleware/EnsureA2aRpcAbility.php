<?php

namespace Webong\Gouge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureA2aRpcAbility
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $this->resolveRpcId($request),
                'error' => [
                    'code' => -32001,
                    'message' => 'A2A authentication is required.',
                ],
            ], 401);
        }

        $payload = $request->json()->all();
        $calls = (is_array($payload) && array_is_list($payload)) ? $payload : [$payload];
        $abilities = config('a2a.inbound.auth.method_abilities', []);

        foreach ($calls as $call) {
            if (! is_array($call)) {
                continue;
            }

            $method = $call['method'] ?? null;

            if (! is_string($method) || trim($method) === '') {
                continue;
            }

            $requiredAbility = $abilities[$method] ?? null;

            if (! is_string($requiredAbility) || trim($requiredAbility) === '') {
                continue;
            }

            if (! is_object($user) || ! method_exists($user, 'tokenCan') || ! $user->tokenCan($requiredAbility)) {
                return response()->json([
                    'jsonrpc' => '2.0',
                    'id' => (is_string($call['id'] ?? null) || is_int($call['id'] ?? null)) ? $call['id'] : null,
                    'error' => [
                        'code' => -32003,
                        'message' => 'A2A method is not authorized for this token.',
                        'data' => [
                            'method' => $method,
                            'required_ability' => $requiredAbility,
                        ],
                    ],
                ], 403);
            }
        }

        return $next($request);
    }

    protected function resolveRpcId(Request $request): string|int|null
    {
        $payload = $request->json()->all();

        if (is_array($payload) && array_is_list($payload)) {
            $first = $payload[0] ?? null;

            if (! is_array($first)) {
                return null;
            }

            $id = $first['id'] ?? null;

            return (is_string($id) || is_int($id)) ? $id : null;
        }

        if (! is_array($payload)) {
            return null;
        }

        $id = $payload['id'] ?? null;

        return (is_string($id) || is_int($id)) ? $id : null;
    }
}
