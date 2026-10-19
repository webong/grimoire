<?php

return [
    'presenter' => 'coordinator_agent',

    'channel' => [
        'default_provider' => 'openai-compatible',
        'transport' => 'http',
        'transports' => ['http'],
        'capabilities' => ['ai.invoke', 'ai.complete', 'ai.review'],
    ],

    'mcp_client' => [
        'default_handler' => env('MCP_DEFAULT_HANDLER'),
        'default_timeout_ms' => (int) env('MCP_TIMEOUT_MS', 8000),
        'max_timeout_ms' => (int) env('MCP_MAX_TIMEOUT_MS', 30000),
        'trust' => [
            'allow_http' => env('MCP_ALLOW_HTTP'),
            'allow_private_network' => env('MCP_ALLOW_PRIVATE_NETWORK'),
            'allowed_hosts' => array_values(array_filter(array_map(
                static fn (string $host): string => trim($host),
                explode(',', (string) env('MCP_ALLOWED_HOSTS', ''))
            ))),
        ],
    ],

    'model_providers' => [
        'anthropic' => ['factory' => 'createAnthropicDriver'],
        'azure' => ['factory' => 'createAzureDriver'],
        'bedrock' => ['factory' => 'createBedrockDriver'],
        'deepseek' => ['factory' => 'createDeepseekDriver'],
        'gemini' => ['factory' => 'createGeminiDriver'],
        'groq' => ['factory' => 'createGroqDriver'],
        'mistral' => ['factory' => 'createMistralDriver'],
        'ollama' => ['factory' => 'createOllamaDriver'],
        'openai' => ['factory' => 'createOpenaiDriver'],
        'openai-compatible' => ['factory' => 'createOpenaiCompatibleDriver'],
        'openrouter' => ['factory' => 'createOpenrouterDriver'],
        'xai' => ['factory' => 'createXaiDriver'],
    ],
];
