<?php

declare(strict_types=1);

namespace Modules\Cart\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected $table = 'cart_items';

    protected $fillable = [
        'cart_id',
        'sku',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'cart_id' => 'integer',
            'quantity' => 'integer',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }
}
