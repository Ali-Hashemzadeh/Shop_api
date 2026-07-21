<?php

declare(strict_types=1);

namespace Modules\Notification\Domain\Enums;

/**
 * The `type` written on every stored notification — a stable machine key the
 * frontend can switch on for icons, grouping, and deep links.
 *
 * These are internal constants, like [[NotificationTemplate]]. The Notification
 * module still holds no business *rules*: which type a flow raises, and its
 * copy, live in the listener for that flow.
 */
enum NotificationType: string
{
    case PAYMENT_SUCCESS = 'payment_success';
    case PAYMENT_FAILED = 'payment_failed';
    case ORDER_CANCELLED = 'order_cancelled';
    case SHIPMENT_PREPARING = 'shipment_preparing';
    case SHIPMENT_SENT = 'shipment_sent';
    case SHIPMENT_DELIVERED = 'shipment_delivered';

    /** Admin-facing: a new paid order landed. In-app only. */
    case ADMIN_ORDER_PAID = 'admin_order_paid';
}
