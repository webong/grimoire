<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\Tools;

use Grimlock\LaravelAi\Contracts\SkillCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request as ToolRequest;
use Stringable;

// Replaces Figurate's application-bound discovery tool with the Grimlock
// catalog. The host supplies the catalog implementation.
final class DiscoverManagedSkillsTool implements Tool
{
    public function __construct(
        private readonly SkillCatalog $catalog,
    ) {}

    public function description(): Stringable|string
    {
        return 'Discover approved Grimlock skills and retrieve their metadata.';
    }

    public function handle(ToolRequest $request): Stringable|string
    {
        $query = trim((string) ($request['query'] ?? ''));
        $limit = max(1, min(25, (int) ($request['limit'] ?? 10)));
        $skills = $this->catalog->discover($query, $limit);

        return json_encode([
            'ok' => true,
            'count' => count($skills),
            'skills' => array_map(static fn ($skill): array => $skill->toArray((bool) ($request['include_content_ref'] ?? false)), $skills),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string(),
            'limit' => $schema->integer(),
            'include_content_ref' => $schema->boolean(),
        ];
    }
}
