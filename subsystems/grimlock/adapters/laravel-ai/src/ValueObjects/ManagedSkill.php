<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\ValueObjects;

use InvalidArgumentException;

final readonly class ManagedSkill
{
    /** @param list<string> $references */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public string $contentRef,
        public string $digest,
        public array $references = [],
    ) {
        if (trim($this->id) === '' || trim($this->name) === '') {
            throw new InvalidArgumentException('Skill ID and name are required.');
        }
        if (trim($this->contentRef) === '' || trim($this->digest) === '') {
            throw new InvalidArgumentException('Skill content reference and digest are required.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(bool $includeContentReference = false): array
    {
        $value = [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'digest' => $this->digest,
            'references' => $this->references,
        ];

        if ($includeContentReference) {
            $value['content_ref'] = $this->contentRef;
        }

        return $value;
    }
}
