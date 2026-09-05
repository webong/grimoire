<?php

namespace Webong\LaravelGouge\Ai\Gateways\Mcp\Tools;

use App\Contracts\Users\UserRepository;
use App\Models\Server\ThreadActor;
use App\Models\Server\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Support\FigurateMcpPayloads;

#[Description('Assign or update a thread actor for a thread.')]
class AssignThreadActorTool extends Tool
{
    public function handle(Request $request, FigurateMcpPayloads $payloads, UserRepository $userRepository): Response
    {
        $validated = $request->validate([
            'thread_id' => ['required', 'string'],
            'actor_type' => ['required', 'string', 'in:named,user'],
            'actor_key' => ['nullable', 'string'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'role' => ['required', 'string'],
            'status' => ['nullable', 'string'],
            'priority' => ['nullable', 'integer', 'min:0'],
        ]);

        $actor = $payloads->actor($request);
        $thread = $payloads->resolveUpdatableThread($actor, (string) $validated['thread_id']);
        $role = (string) $validated['role'];
        $status = (string) ($validated['status'] ?? ThreadActor::StatusActive);

        abort_unless(in_array($role, $payloads->allowedActorRoles(), true), 422, 'Unsupported role.');
        abort_unless(in_array($status, $payloads->allowedActorStatuses(), true), 422, 'Unsupported status.');

        if ((string) $validated['actor_type'] === 'named') {
            $actorKey = trim((string) ($validated['actor_key'] ?? ''));
            abort_unless(in_array($actorKey, $payloads->allowedNamedActors(), true), 422, 'Unsupported named actor.');

            $threadActor = $thread->actors()->updateOrCreate(
                [
                    'actorable_type' => $actorKey,
                    'actorable_id' => null,
                    'role' => $role,
                ],
                [
                    'status' => $status,
                    'priority' => $validated['priority'] ?? null,
                    'config' => null,
                ],
            );
        } else {
            $userId = (int) ($validated['user_id'] ?? 0);
            abort_if($userId <= 0, 422, 'user_id is required when actor_type is user.');

            $targetUser = $userRepository->findById($userId);
            abort_unless($targetUser instanceof User, 404);

            $threadActor = $thread->actors()->updateOrCreate(
                [
                    'actorable_type' => $targetUser->getMorphClass(),
                    'actorable_id' => $targetUser->getKey(),
                    'role' => $role,
                ],
                [
                    'status' => $status,
                    'priority' => $validated['priority'] ?? null,
                    'config' => null,
                ],
            );
        }

        return Response::json([
            'actor' => $payloads->mapActor($threadActor),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'thread_id' => $schema->string()->description('The thread UUID.')->required(),
            'actor_type' => $schema->string()->description('Assign a named actor or a user.')->required(),
            'actor_key' => $schema->string()->description('Named actor key, for example coordinator_agent.'),
            'user_id' => $schema->integer()->description('User ID for a human actor assignment.'),
            'role' => $schema->string()->description('Actor role in the thread.')->required(),
            'status' => $schema->string()->description('Actor status.')->default(ThreadActor::StatusActive),
            'priority' => $schema->integer()->description('Optional actor priority.'),
        ];
    }
}
