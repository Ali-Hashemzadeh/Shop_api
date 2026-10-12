<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Enums;

/**
 * Why a Media asset is attached to an article. Alt text and caption are per article.
 */
enum BlogPostMediaRole: string
{
    case Inline = 'inline';
    case Gallery = 'gallery';
    case Attachment = 'attachment';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
