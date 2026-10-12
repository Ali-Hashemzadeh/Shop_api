<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\DTOs;

use Modules\Media\Domain\DTOs\MediaDTO;

/**
 * A resolved Media asset as Blog presents it: the public URL from the Media
 * contract plus the Blog-specific alt text / caption / role of this usage.
 */
final class BlogMediaDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $url,
        public readonly ?string $mimeType = null,
        public readonly ?string $altText = null,
        public readonly ?string $caption = null,
        public readonly ?string $role = null,
        public readonly int $sortOrder = 0,
    ) {}

    public static function fromMedia(
        MediaDTO $media,
        ?string $altText = null,
        ?string $caption = null,
        ?string $role = null,
        int $sortOrder = 0,
    ): self {
        return new self(
            id: $media->id,
            url: $media->url,
            mimeType: $media->mimeType,
            altText: $altText,
            caption: $caption,
            role: $role,
            sortOrder: $sortOrder,
        );
    }
}
