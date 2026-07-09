<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Graph;

/** Catégorie (référencée par les produits via category_ids). */
final class Category
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $path = null,
        public readonly ?string $parentId = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $out = ['id' => $this->id, 'name' => $this->name];
        if ($this->path !== null) {
            $out['path'] = $this->path;
        }
        if ($this->parentId !== null) {
            $out['parent_id'] = $this->parentId;
        }

        return $out;
    }
}
