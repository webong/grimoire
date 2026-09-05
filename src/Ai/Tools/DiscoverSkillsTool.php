<?php

namespace Webong\LaravelGouge\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request as ToolRequest;
use Stringable;
use Webong\LaravelGouge\Ai\Support\Skills\SkillRepository;

class DiscoverSkillsTool implements Tool
{
    public function __construct(protected SkillRepository $skillRepository = new SkillRepository) {}

    public function description(): Stringable|string
    {
        return 'Discover agent skills from and retrieve concise guidance snippets.';
    }

    public function handle(ToolRequest $request): Stringable|string
    {
        $query = mb_strtolower(trim((string) ($request['query'] ?? '')));
        $limit = max(1, min(25, (int) ($request['limit'] ?? 10)));
        $includeContent = (bool) ($request['include_content'] ?? false);
        $skills = $this->skillRepository->discover($query, $limit, $includeContent);

        return json_encode([
            'ok' => true,
            'count' => count($skills),
            'skills' => $skills,
        ], JSON_UNESCAPED_SLASHES);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string(),
            'limit' => $schema->integer(),
            'include_content' => $schema->boolean(),
        ];
    }
}
