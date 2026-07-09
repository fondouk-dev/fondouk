<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Query;

/** Pagination cursor-based. Défaut 10, max 50 (décision §4.4 cache/rate-limiting). */
final class Pagination
{
    public const DEFAULT_LIMIT = 10;
    public const MAX_LIMIT = 50;

    public function __construct(
        public readonly int $limit = self::DEFAULT_LIMIT,
        public readonly ?string $cursor = null,
    ) {
    }

    /** Limite bornée [1, MAX_LIMIT]. */
    public function clampedLimit(): int
    {
        return max(1, min(self::MAX_LIMIT, $this->limit));
    }
}
