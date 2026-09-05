<?php

use App\Models\Server\Post;

return [
    'post_models' => [
        'default' => Post::class,
    ],
    'sub_agents' => [
        'trace_reuse_window_seconds' => (int) env('AI_SUB_AGENT_TRACE_REUSE_WINDOW_SECONDS', 120),
        'limits' => [
            'max_invocations_per_trace' => (int) env('AI_SUB_AGENT_MAX_INVOCATIONS_PER_TRACE', 6),
            'max_invocations_per_sub_agent_per_trace' => (int) env('AI_SUB_AGENT_MAX_INVOCATIONS_PER_SUB_AGENT_PER_TRACE', 3),
        ],
        'allowed_by_actor' => [
            '*' => ['manager', 'planner', 'developer', 'explorer', 'researcher', 'browser'],
        ],
    ],
];
