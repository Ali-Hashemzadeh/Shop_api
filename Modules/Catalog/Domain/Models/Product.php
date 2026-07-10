<?php

namespace Modules\Catalog\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'category_id',
        'title',
        'slug',
        'description',
        'features',
        'status',
        'primary_media_id',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'sales_count' => 'integer',
        ];
    }

    /**
     * The public identifier is a short, opaque code, generated server-side and
     * never accepted from client input (mirrors the auto-generated SKU rule). The
     * integer primary key remains the internal key and foreign-key target.
     */
    protected static function booted(): void
    {
        static::creating(function (Product $product): void {
            if (empty($product->uuid)) {
                $product->uuid = static::generateUniqueUuid();
            }
        });
    }

    /**
     * Generate a 7-character public identifier. Hex-only so it satisfies the
     * product route constraint (`[0-9a-fA-F-]`) and never collides with reserved
     * path segments like `admin` / `slug`. The loop rejects any value already in
     * the table, and the `uuid` unique index is the hard backstop — so the
     * returned code is genuinely unique.
     */
    public static function generateUniqueUuid(): string
    {
        do {
            $code = substr(bin2hex(random_bytes(4)), 0, 7);
        } while (static::query()->where('uuid', $code)->exists());

        return $code;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class, 'product_id')->orderBy('sort_order');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'product_id');
    }
}
