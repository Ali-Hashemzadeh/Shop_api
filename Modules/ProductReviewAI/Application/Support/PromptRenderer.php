<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Support;

/**
 * Substitutes `{{token}}` placeholders in a stored prompt template. Unknown
 * tokens are left intact so a template change never silently drops context.
 */
class PromptRenderer
{
    /**
     * @param  array<string, scalar>  $vars
     */
    public static function render(string $template, array $vars): string
    {
        $replacements = [];

        foreach ($vars as $key => $value) {
            $replacements['{{'.$key.'}}'] = (string) $value;
        }

        return strtr($template, $replacements);
    }
}
