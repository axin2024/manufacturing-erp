<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class Scope
{
    public function __construct(
        public readonly int $user,
        public readonly int $org,
        public readonly int $tenant,
        public readonly string $currency,
        public readonly string $code,
        public readonly string $name,
        public readonly string $actor,
        public readonly array $roles,
    ) {
    }

    public function can(string ...$roles): bool
    {
        return in_array('admin', $this->roles, true) || array_intersect($this->roles, $roles) !== [];
    }
}
