<?php

namespace Webong\Gouge\Runtime;

use InvalidArgumentException;

final readonly class AgentTurn
{
    public function __construct(
        public string $scopeId,
        public string $sessionId,
        public string $runId,
        public string $agentName,
        public string $agentVersion,
        public string $definitionDigest,
        public string $prompt,
        public array $metadata = [],
    ) {
        foreach ([$scopeId, $sessionId, $runId, $agentName, $agentVersion] as $id) {
            if (trim($id) === '' || strlen($id) > 128) {
                throw new InvalidArgumentException('Runtime identifiers must contain 1–128 bytes.');
            }
        }
        if (! preg_match('/^[a-f0-9]{64}$/D', $definitionDigest)) {
            throw new InvalidArgumentException('A pinned SHA-256 definition digest is required.');
        }
        if (trim($prompt) === '') {
            throw new InvalidArgumentException('A non-empty prompt is required.');
        }
        if (str_contains($agentName, '@') || str_contains($agentVersion, '@')) {
            throw new InvalidArgumentException('Agent name and version must not contain the registration separator.');
        }
        self::validateMetadata($metadata);
        json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    private static function validateMetadata(array $metadata): void
    {
        foreach ($metadata as $value) {
            if (is_array($value)) {
                self::validateMetadata($value);
            } elseif ($value !== null && ! is_scalar($value)) {
                throw new InvalidArgumentException('Metadata must contain only JSON values, never model objects or callbacks.');
            }
        }
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }

    public function definitionKey(): string
    {
        return json_encode([$this->agentName, $this->agentVersion, $this->definitionDigest], JSON_THROW_ON_ERROR);
    }
}
