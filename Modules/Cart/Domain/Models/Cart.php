<?php

declare(strict_types=1);

namespace Modules\Cart\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    protected $table = 'carts';

    protected $fillable = [
        'user_id',
        'session_id',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }
}
