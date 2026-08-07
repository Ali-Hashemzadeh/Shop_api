<?php

namespace Modules\Identity\Domain\Models;

use App\Support\HasPublicCode;
use App\Support\PublicCodeEntity;
use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Address extends Model
{
    use HasFactory;
    use HasPublicCode;

    protected $fillable = [
        'user_id',
        'title',
        'province_id',
        'city_id',
        'postal_code',
        'address',
        'latitude',
        'longitude',
        'map_address',
        'is_default_shipping',
    ];

    protected $casts = [
        'is_default_shipping' => 'boolean',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    /**
     * Customer-facing handle (`bda-XXXXXX`) for display and address search.
     * The integer id keeps every structural role: foreign keys, ownership,
     * checkout's `address_id`, Shipment eligibility, mutation routes, and
     * admin route bindings. Customer show resolves this public code instead.
     */
    public static function publicCodeEntity(): PublicCodeEntity
    {
        return PublicCodeEntity::Address;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    protected static function newFactory()
    {
        return AddressFactory::new();
    }
}
