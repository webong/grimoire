<?php

namespace Webong\Gouge\Ai\Support\A2ui;

class A2uiPayloadContract
{
    /**
     * @param  array<string, mixed>|null  $capabilities
     * @return array<string, mixed>|null
     */
    public function normalizeClientCapabilities(?array $capabilities): ?array
    {
        if (! is_array($capabilities)) {
            return null;
        }

        $normalized = [];

        $supportedCatalogIds = collect($capabilities['supportedCatalogIds'] ?? [])
            ->map(fn (mixed $entry): ?string => $this->trimmedString($entry))
            ->filter(fn (mixed $entry): bool => is_string($entry) && $entry !== '')
            ->values()
            ->all();

        if ($supportedCatalogIds !== []) {
            $normalized['supportedCatalogIds'] = $supportedCatalogIds;
        }

        if (array_key_exists('acceptsInlineCatalogs', $capabilities)) {
            $normalized['acceptsInlineCatalogs'] = (bool) $capabilities['acceptsInlineCatalogs'];
        }

        return $normalized === [] ? null : $normalized;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>|null
     */
    public function normalizeAction(array $action): ?array
    {
        $protocol = $this->trimmedString($action['protocol'] ?? null) ?? 'a2ui';
        $name = $this->trimmedString($action['name'] ?? $action['type'] ?? null);
        $id = $this->trimmedString($action['id'] ?? null);
        $surfaceId = $this->trimmedString($action['surfaceId'] ?? null);
        $sourceComponentId = $this->trimmedString($action['sourceComponentId'] ?? null);
        $timestamp = $this->trimmedString($action['timestamp'] ?? null);
        $context = $this->normalizeAssocArray($action['context'] ?? null);
        $values = $this->normalizeAssocArray($action['values'] ?? null);

        if ($name === null && $id === null && $surfaceId === null && $sourceComponentId === null && $timestamp === null && $context === [] && $values === []) {
            return null;
        }

        return array_filter([
            'protocol' => $protocol,
            'name' => $name,
            'id' => $id,
            'surfaceId' => $surfaceId,
            'sourceComponentId' => $sourceComponentId,
            'timestamp' => $timestamp,
            'context' => $context !== [] ? $context : null,
            'values' => $values !== [] ? $values : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $error
     * @return array<string, mixed>|null
     */
    public function normalizeError(array $error): ?array
    {
        $protocol = $this->trimmedString($error['protocol'] ?? null) ?? 'a2ui';
        $code = $this->trimmedString($error['code'] ?? null);
        $path = $this->trimmedString($error['path'] ?? null);
        $message = $this->trimmedString($error['message'] ?? null);
        $userActionRaw = $error['userAction'] ?? null;
        $userAction = is_array($userActionRaw) ? $this->normalizeAction($userActionRaw) : null;

        if ($code === null && $path === null && $message === null && $userAction === null) {
            return null;
        }

        return array_filter([
            'protocol' => $protocol,
            'code' => $code,
            'path' => $path,
            'message' => $message,
            'userAction' => $userAction,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public function looksLikeAction(array $value): bool
    {
        return $this->trimmedString($value['name'] ?? $value['type'] ?? null) !== null
            || $this->trimmedString($value['id'] ?? null) !== null;
    }

    protected function trimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeAssocArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return $this->isAssoc($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    protected function isAssoc(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }
}
