<?php

namespace Webong\LaravelGouge\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webong\LaravelGouge\Features\Actions\Chat\ProjectInvocationTurns;

class FormTurnsController extends Controller
{
    public function __construct(
        protected ProjectInvocationTurns $projectInvocationTurns,
    ) {}

    public function __invoke(Request $request, string $invocation): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $turn = $this->projectInvocationTurns->execute($actor, $invocation);

        return response()->json([
            'data' => [$turn],
            'invocation_id' => $invocation,
            'trace_id' => $turn['trace_id'] ?? null,
        ]);
    }
}
