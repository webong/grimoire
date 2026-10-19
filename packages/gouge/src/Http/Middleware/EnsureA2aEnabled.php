<?php

namespace Webong\Gouge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureA2aEnabled
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) config('a2a.inbound.enabled', true)) {
            return $next($request);
        }

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $this->resolveRpcId($request),
            'error' => [
                'code' => -32004,
                'message' => 'A2A is disabled.',
            ],
        ], 503);
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

            return is_string($id) || is_int($id) ? $id : null;
        }

        if (! is_array($payload)) {
            return null;
        }

        $id = $payload['id'] ?? null;

        return is_string($id) || is_int($id) ? $id : null;
    }
}
