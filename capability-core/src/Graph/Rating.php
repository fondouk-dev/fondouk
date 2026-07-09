<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Graph;

/** Note agrégée. En PrestaShop, dépend du module ps_productcomments (décision Q8). */
final class Rating
{
    public function __construct(
        public readonly float $value,
        public readonly ?float $scaleMax = null,
        public readonly ?int $count = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $out = ['value' => $this->value];
        if ($this->scaleMax !== null) {
            $out['scale_max'] = $this->scaleMax;
        }
        if ($this->count !== null) {
            $out['count'] = $this->count;
        }

        return $out;
    }
}
