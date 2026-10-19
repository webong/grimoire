<?php

namespace Webong\Gouge\Ai\Gateways\Mcp\Tools;

use App\Models\Server\Thread;
use App\Models\Server\ThreadActor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Webong\Gouge\Ai\Gateways\Mcp\Support\FigurateMcpPayloads;

#[Description('Create a new thread inside a space.')]
class CreateThreadTool extends Tool
{
    public function handle(Request $request, FigurateMcpPayloads $payloads): Response
    {
        $validated = $request->validate([
            'space_id' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
            'phase' => ['nullable', 'string', 'max:100'],
            'add_self_as_member' => ['nullable', 'boolean'],
        ]);

        $actor = $payloads->actor($request);
        $space = $payloads->resolveUpdatableSpace($actor, (string) $validated['space_id']);
        $purpose = (string) ($validated['purpose'] ?? Thread::PurposeMain);
        $thread = $space->threads()->create([
            'title' => (string) $validated['title'],
            'purpose' => $purpose,
            'phase' => (string) ($validated['phase'] ?? Thread::PhaseInitial),
            'status' => (string) ($validated['status'] ?? 'open'),
        ]);

        if ((bool) ($validated['add_self_as_member'] ?? true)) {
            $thread->actors()->create([
                'actorable_type' => $actor->getMorphClass(),
                'actorable_id' => $actor->getKey(),
                'role' => ThreadActor::RoleMember,
                'status' => ThreadActor::StatusActive,
                'priority' => 1,
                'config' => null,
            ]);
        }

        return Response::json([
            'thread' => $payloads->mapThread($thread),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'space_id' => $schema->string()->description('The parent space UUID.')->required(),
            'title' => $schema->string()->description('The thread title.')->required(),
            'purpose' => $schema->string()->description('Optional caller-defined thread purpose.')->default(Thread::PurposeMain),
            'status' => $schema->string()->description('Optional thread status.')->default('open'),
            'phase' => $schema->string()->description('Optional caller-defined thread phase.')->default(Thread::PhaseInitial),
            'add_self_as_member' => $schema->boolean()->description('Whether to add the authenticated actor as an active member.')->default(true),
        ];
    }
}
