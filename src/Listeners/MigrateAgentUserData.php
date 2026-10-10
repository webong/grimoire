<?php

namespace Webong\Gouge\Listeners;

use App\Events\Server\Auth\WidgetUsersMerging;
use App\Models\Server\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MigrateAgentUserData
{
    public function handle(WidgetUsersMerging $event): void
    {
        $this->migrateThreadActorSessions($event->source, $event->target);
        $this->migrateAgentConversations($event->source, $event->target);
        $this->migrateAgentConversationMessages($event->source, $event->target);
    }

    protected function migrateThreadActorSessions(User $source, User $target): void
    {
        if (! Schema::hasTable('thread_actor_sessions')) {
            return;
        }

        $rows = DB::table('thread_actor_sessions')
            ->where('user_id', $source->id)
            ->get();

        foreach ($rows as $row) {
            $existing = DB::table('thread_actor_sessions')
                ->where('thread_actor_id', $row->thread_actor_id)
                ->where('user_id', $target->id)
                ->where('provider', $row->provider)
                ->where('model', $row->model)
                ->first();

            if (! $existing) {
                DB::table('thread_actor_sessions')
                    ->where('id', $row->id)
                    ->update([
                        'user_id' => $target->id,
                        'updated_at' => now(),
                    ]);

                continue;
            }

            $sourceLastUsed = $row->last_used_at ? strtotime((string) $row->last_used_at) : null;
            $targetLastUsed = $existing->last_used_at ? strtotime((string) $existing->last_used_at) : null;

            if ($sourceLastUsed !== null && ($targetLastUsed === null || $sourceLastUsed > $targetLastUsed)) {
                DB::table('thread_actor_sessions')
                    ->where('id', $existing->id)
                    ->update([
                        'conversation_id' => $row->conversation_id,
                        'state' => $row->state,
                        'last_used_at' => $row->last_used_at,
                        'updated_at' => now(),
                    ]);
            }

            DB::table('thread_actor_sessions')
                ->where('id', $row->id)
                ->delete();
        }
    }

    protected function migrateAgentConversations(User $source, User $target): void
    {
        if (! Schema::hasTable('agent_conversations')) {
            return;
        }

        DB::table('agent_conversations')
            ->where('participant_type', $source->getMorphClass())
            ->where('participant_id', $source->id)
            ->update([
                'participant_type' => $target->getMorphClass(),
                'participant_id' => $target->id,
                'updated_at' => now(),
            ]);
    }

    protected function migrateAgentConversationMessages(User $source, User $target): void
    {
        if (! Schema::hasTable('agent_conversation_messages')) {
            return;
        }

        DB::table('agent_conversation_messages')
            ->where('participant_type', $source->getMorphClass())
            ->where('participant_id', $source->id)
            ->update([
                'participant_type' => $target->getMorphClass(),
                'participant_id' => $target->id,
                'updated_at' => now(),
            ]);
    }
}
