<?php

declare(strict_types=1);

namespace Modules\Notification\Domain\Enums;

/**
 * Internal, provider-independent SMS template names.
 *
 * These are constants owned by our system — they never change when an SMS
 * provider is swapped, and they never come from configuration or client input.
 * Configuration only maps each name to a provider's own template id, e.g.
 * `payment_success` → `SMS_SMSIR_PAYMENT_SUCCESS_TEMPLATE_ID`.
 *
 * A name may exist here without a configured provider template id: the SMS is
 * then skipped, never failed. Adding a case is safe on its own.
 */
enum NotificationTemplate: string
{
    case PAYMENT_SUCCESS = 'payment_success';
    case ORDER_CANCELLED = 'order_cancelled';
    case SHIPMENT_PREPARING = 'shipment_preparing';
    case SHIPMENT_SENT = 'shipment_sent';
    case SHIPMENT_DELIVERED = 'shipment_delivered';
}
