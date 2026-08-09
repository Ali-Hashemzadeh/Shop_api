<?php

namespace Modules\Catalog\Domain\Models;

use App\Support\HasPublicCode;
use App\Support\PublicCodeEntity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    use HasPublicCode;

    protected $fillable = [
        'product_id',
        'sku',
        'type',
        'is_default',
        // The regular price, and the only price this module stores. Promotional
        // pricing is evaluated live by the Promotion module on every read.
        'base_price',
        'max_quantity_per_order',
        'media_id',
        'attributes',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'base_price' => 'integer',
            'max_quantity_per_order' => 'integer',
            'attributes' => 'array',
        ];
    }

    /**
     * The SKU *is* the variant's public code — it is already the identifier Cart,
     * Inventory, Order items, and reservations exchange across module walls, so a
     * second column would be a duplicate handle for the same thing.
     *
     * Server-generated only, never accepted from client input, and never
     * regenerated on update: SKUs already in flight through other modules must
     * keep resolving forever.
     */
    public static function publicCodeEntity(): PublicCodeEntity
    {
        return PublicCodeEntity::ProductVariant;
    }

    public static function publicCodeColumn(): string
    {
        return 'sku';
    }

    /** Intention-revealing alias for the one place SKUs are minted. */
    public static function generateUniqueSku(): string
    {
        return static::generateUniquePublicCode();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
