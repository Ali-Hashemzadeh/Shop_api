<?php

namespace Modules\Payment\Domain\Models;

use App\Support\HasPublicCode;
use App\Support\PublicCodeEntity;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasPublicCode;

    protected $fillable = [
        'order_id',
        'method_type',
        'gateway',
        'transaction_reference',
        'amount',
        'status',
        'gateway_response',
    ];

    protected $casts = [
        'amount' => 'integer',
        'gateway_response' => 'array',
    ];

    /**
     * Customer-facing support reference (`bdt-XXXXXX`).
     *
     * Distinct from `transaction_reference`, which is the gateway's own authority
     * / RefID and stays the sole key for callback lookup, verification, and
     * idempotency. The public code is never sent to or accepted from a gateway.
     */
    public static function publicCodeEntity(): PublicCodeEntity
    {
        return PublicCodeEntity::Payment;
    }
}
