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
    case SHIPMENT_DELIVERED = 'shipment_delivered';

    /**
     * Legacy. Every dispatch used to be one generic "sent" notification, whatever
     * the fulfillment shape. Nothing raises it any more — it stays defined because
     * rows written before the split still carry it and history is never rewritten.
     * The frontend must keep rendering it; new code must not emit it.
     */
    case SHIPMENT_SENT = 'shipment_sent';

    /** Pickup order waiting at the counter. */
    case SHIPMENT_READY_FOR_PICKUP = 'shipment_ready_for_pickup';

    /** Postal parcel handed over to the carrier; carries the tracking code. */
    case SHIPMENT_HANDED_TO_POST = 'shipment_handed_to_post';

    /** Local delivery on the road. Never carries the handoff code — that is SMS-only. */
    case SHIPMENT_OUT_FOR_DELIVERY = 'shipment_out_for_delivery';

    /** Courier-facing: a local delivery was assigned to this delivery worker. */
    case SHIPMENT_ASSIGNED_DELIVERY = 'shipment_assigned_delivery';

    /** Admin-facing: a new paid order landed. In-app only. */
    case ADMIN_ORDER_PAID = 'admin_order_paid';
}
