<?php

namespace Modules\Catalog\Domain\Models;

use App\Support\HasPublicCode;
use App\Support\PublicCodeEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasPublicCode;

    protected $fillable = [
        'category_id',
        'brand_id',
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
            'rating_sum' => 'integer',
            'rating_count' => 'integer',
        ];
    }

    /**
     * The public identifier is a short, opaque code generated server-side and
     * never accepted from client input (mirrors the auto-generated SKU rule).
     * The integer primary key remains the internal key and foreign-key target.
     *
     * The column is named `uuid` for historical reasons and keeps that name so
     * existing URLs, bookmarks, and callers stay valid — the value it holds has
     * never actually been a v4 UUID.
     */
    public static function publicCodeEntity(): PublicCodeEntity
    {
        return PublicCodeEntity::Product;
    }

    public static function publicCodeColumn(): string
    {
        return 'uuid';
    }

    /**
     * Compatibility wrapper for the pre-public-code name, still used by seeders
     * and older callers. New code should prefer generateUniquePublicCode().
     */
    public static function generateUniqueUuid(): string
    {
        return static::generateUniquePublicCode();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
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
