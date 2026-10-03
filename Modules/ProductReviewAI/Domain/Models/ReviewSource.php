<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A registered external marketplace. `driver` is a stable code (never a PHP
 * namespace) that ReviewSourceFactory resolves to a concrete adapter.
 *
 * @property string $code
 * @property string $driver
 */
class ReviewSource extends Model
{
    protected $fillable = [
        'code',
        'name',
        'driver',
        'config',
        'is_active',
    ];

    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
    ];
}
