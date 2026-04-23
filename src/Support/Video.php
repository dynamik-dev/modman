<?php

declare(strict_types=1);

namespace Dynamik\Modman\Support;

final readonly class Video
{
    public function __construct(
        public string $url,
        public ?string $mimeType = null,
    ) {}
}
