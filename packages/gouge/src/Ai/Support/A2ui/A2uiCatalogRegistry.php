<?php

namespace Webong\Gouge\Ai\Support\A2ui;

class A2uiCatalogRegistry
{
    /**
     * @return array<int, string>
     */
    public function supportedCatalogIds(): array
    {
        $configuredIds = collect(config('a2a.inbound.a2ui.catalogs.supported_ids', []))
            ->map(fn (mixed $entry): ?string => $this->trimmedString($entry))
            ->filter(fn (mixed $entry): bool => is_string($entry) && $entry !== '')
            ->values();

        $itemIds = collect($this->catalogItems())
            ->map(function (mixed $catalog, mixed $key): ?string {
                if (is_string($key) && trim($key) !== '') {
                    return trim($key);
                }

                if (! is_array($catalog)) {
                    return null;
                }

                return $this->trimmedString($catalog['id'] ?? null);
            })
            ->filter(fn (mixed $entry): bool => is_string($entry) && $entry !== '')
            ->values();

        return $configuredIds
            ->merge($itemIds)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $catalogIds
     * @return array<int, array<string, mixed>>
     */
    public function catalogsByIds(array $catalogIds): array
    {
        return collect($catalogIds)
            ->map(fn (mixed $catalogId): ?array => is_string($catalogId) ? $this->catalogById($catalogId) : null)
            ->filter(fn (mixed $catalog): bool => is_array($catalog))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $clientCapabilities
     * @return array<string, mixed>
     */
    public function decoratePayload(array $payload, ?array $clientCapabilities): array
    {
        $referencedIds = $this->referencedCatalogIds($payload);

        if ($referencedIds === []) {
            return $payload;
        }

        $allowedCatalogIds = $this->resolveAllowedCatalogIds($clientCapabilities);

        if ($allowedCatalogIds === []) {
            return $payload;
        }

        $resolvedIds = collect($referencedIds)
            ->filter(fn (string $catalogId): bool => in_array($catalogId, $allowedCatalogIds, true))
            ->values()
            ->all();

        if ($resolvedIds === []) {
            return $payload;
        }

        $acceptsInlineCatalogs = is_array($clientCapabilities) && array_key_exists('acceptsInlineCatalogs', $clientCapabilities)
            ? (bool) $clientCapabilities['acceptsInlineCatalogs']
            : (bool) config('a2a.inbound.a2ui.catalogs.accepts_inline', true);

        if (! $acceptsInlineCatalogs) {
            $payload['catalogRefs'] = $resolvedIds;

            return $payload;
        }

        $existingCatalogs = is_array($payload['catalogs'] ?? null) ? $payload['catalogs'] : [];
        $mergedCatalogs = collect([
            ...$existingCatalogs,
            ...$this->catalogsByIds($resolvedIds),
        ])
            ->map(function (mixed $catalog): ?array {
                if (! is_array($catalog)) {
                    return null;
                }

                $id = $this->trimmedString($catalog['id'] ?? null);
                if ($id === null) {
                    return null;
                }

                return [
                    ...$catalog,
                    'id' => $id,
                ];
            })
            ->filter(fn (mixed $catalog): bool => is_array($catalog))
            ->unique(fn (array $catalog): string => (string) $catalog['id'])
            ->values()
            ->all();

        if ($mergedCatalogs !== []) {
            $payload['catalogs'] = $mergedCatalogs;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function catalogById(string $catalogId): ?array
    {
        $catalogId = trim($catalogId);

        if ($catalogId === '') {
            return null;
        }

        $items = $this->catalogItems();
        $direct = $items[$catalogId] ?? null;

        if (is_array($direct)) {
            return [
                ...$direct,
                'id' => $this->trimmedString($direct['id'] ?? null) ?? $catalogId,
            ];
        }

        $match = collect($items)
            ->first(function (mixed $catalog) use ($catalogId): bool {
                if (! is_array($catalog)) {
                    return false;
                }

                return $this->trimmedString($catalog['id'] ?? null) === $catalogId;
            });

        if (! is_array($match)) {
            return null;
        }

        return [
            ...$match,
            'id' => $this->trimmedString($match['id'] ?? null) ?? $catalogId,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    protected function referencedCatalogIds(array $payload): array
    {
        $ids = [];
        $walk = function (mixed $value) use (&$ids, &$walk): void {
            if (! is_array($value)) {
                return;
            }

            $catalogId = $this->trimmedString($value['catalogId'] ?? null);
            if ($catalogId !== null) {
                $ids[] = $catalogId;
            }

            $catalogIds = $value['catalogIds'] ?? null;
            if (is_array($catalogIds)) {
                $ids = [
                    ...$ids,
                    ...collect($catalogIds)
                        ->map(fn (mixed $entry): ?string => $this->trimmedString($entry))
                        ->filter(fn (mixed $entry): bool => is_string($entry) && $entry !== '')
                        ->values()
                        ->all(),
                ];
            }

            foreach ($value as $entry) {
                $walk($entry);
            }
        };

        $walk($payload);

        return collect($ids)
            ->filter(fn (mixed $entry): bool => is_string($entry) && $entry !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function catalogItems(): array
    {
        $items = config('a2a.inbound.a2ui.catalogs.items', []);

        return is_array($items) ? $items : [];
    }

    /**
     * @param  array<string, mixed>|null  $clientCapabilities
     * @return array<int, string>
     */
    protected function resolveAllowedCatalogIds(?array $clientCapabilities): array
    {
        $serverSupportedIds = $this->supportedCatalogIds();

        if ($serverSupportedIds === []) {
            return [];
        }

        if (! is_array($clientCapabilities)) {
            return $serverSupportedIds;
        }

        $clientSupportedIds = collect($clientCapabilities['supportedCatalogIds'] ?? [])
            ->map(fn (mixed $entry): ?string => $this->trimmedString($entry))
            ->filter(fn (mixed $entry): bool => is_string($entry) && $entry !== '')
            ->values()
            ->all();

        if ($clientSupportedIds === []) {
            return $serverSupportedIds;
        }

        return collect($serverSupportedIds)
            ->intersect($clientSupportedIds)
            ->values()
            ->all();
    }

    protected function trimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
