<?php

declare(strict_types=1);

namespace App\Data\PixelWorld\Analytics;

final readonly class ChartArtifact
{
    public function __construct(
        public string $path,
        public string $mimeType,
        public int $width,
        public int $height,
        public string $cacheKey,
    ) {}
}
