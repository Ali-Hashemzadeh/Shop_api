<?php

namespace Modules\Payment\Domain\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
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
}
