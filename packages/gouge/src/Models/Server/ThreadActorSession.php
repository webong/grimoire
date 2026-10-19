<?php

namespace Webong\Gouge\Models\Server;

use Database\Factories\ThreadActorSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ThreadActorSession extends Model
{
    /** @use HasFactory<ThreadActorSessionFactory> */
    use HasFactory;

    protected $table = 'thread_actor_sessions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'thread_id',
        'thread_actor_id',
        'user_id',
        'provider',
        'model',
        'conversation_id',
        'state',
        'last_used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'state' => 'array',
            'last_used_at' => 'datetime',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    public function threadActor(): BelongsTo
    {
        return $this->belongsTo(ThreadActor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AgentConversation::class, 'conversation_id', 'id');
    }
}
