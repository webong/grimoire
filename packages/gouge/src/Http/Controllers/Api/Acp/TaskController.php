<?php

namespace Webong\Gouge\Http\Controllers\Api\Acp;

use App\Http\Controllers\Controller;
use App\Models\Server\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webong\Gouge\Support\Acp\AcpSessionService;

class TaskController extends Controller
{
    public function show(Request $request, AcpSessionService $acpSessionService, string $task): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json([
            'data' => $acpSessionService->task($actor, $task),
        ]);
    }

    public function cancel(Request $request, AcpSessionService $acpSessionService, string $task): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json([
            'data' => $acpSessionService->cancelTask($actor, $task),
        ]);
    }
}
