<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\Contracts;

use Grimlock\LaravelAi\ValueObjects\ManagedSkill;

interface SkillCatalog
{
    /** @return list<ManagedSkill> */
    public function discover(string $query = '', int $limit = 10): array;
}
