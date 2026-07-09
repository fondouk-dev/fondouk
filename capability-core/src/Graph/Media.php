<?php

declare(strict_types=1);

namespace Fondouk\CapabilityCore\Graph;

/** Média (image/vidéo/3D). Miroir du type UCP common/types/media.json. */
final class Media
{
    public const IMAGE = 'image';
    public const VIDEO = 'video';
    public const MODEL_3D = 'model_3d';

    public function __construct(
        public readonly string $type,
        public readonly string $url,
        public readonly ?string $altText = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $out = ['type' => $this->type, 'url' => $this->url];
        if ($this->altText !== null) {
            $out['alt_text'] = $this->altText;
        }
        if ($this->width !== null) {
            $out['width'] = $this->width;
        }
        if ($this->height !== null) {
            $out['height'] = $this->height;
        }

        return $out;
    }
}
