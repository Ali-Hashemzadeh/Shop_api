<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A versioned prompt template. A generation records the exact prompt name +
 * version it used, so changing prompts later never rewrites past history.
 *
 * @property string $name
 * @property int $version
 */
class AiPrompt extends Model
{
    protected $fillable = [
        'name',
        'model',
        'version',
        'system_prompt',
        'user_prompt',
        'active',
    ];

    protected $casts = [
        'version' => 'integer',
        'active' => 'boolean',
    ];
}
