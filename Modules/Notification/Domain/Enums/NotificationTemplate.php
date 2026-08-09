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
    case SHIPMENT_DELIVERED = 'shipment_delivered';

    /** Pickup order waiting at the counter. Parameters: `OrderId`. */
    case SHIPMENT_READY_FOR_PICKUP = 'shipment_ready_for_pickup';

    /** Postal handoff. Parameters: `OrderId`, `TrackingCode`. Never mentions a delivery code. */
    case SHIPMENT_HANDED_TO_POST = 'shipment_handed_to_post';

    /**
     * Local delivery on its way, carrying the customer's handoff code.
     * Parameters: `OrderId`, `DeliveryCode`.
     *
     * Separate from the postal template because the code has to appear in the body
     * — and a template that mentions a code must never be used for a postal parcel,
     * which has none. Also used by the resend path, which repeats this same message
     * with a freshly minted code.
     */
    case SHIPMENT_OUT_FOR_DELIVERY = 'shipment_out_for_delivery';

    /** Admin-facing: a new paid order. Parameters: `OrderId`. Only the admins selected in
     *  the Notification recipient settings receive it — see NotificationRecipientPreference. */
    case ADMIN_ORDER_PAID = 'admin_order_paid';

    /**
     * Legacy, superseded by SHIPMENT_HANDED_TO_POST / SHIPMENT_OUT_FOR_DELIVERY.
     * Kept defined (and mapped in config/sms.php) so an existing deployment's
     * provider configuration keeps validating during the changeover. Nothing sends
     * them any more.
     */
    case SHIPMENT_SENT = 'shipment_sent';

    /** @deprecated Legacy sibling of SHIPMENT_SENT — see the note above. */
    case SHIPMENT_SENT_DELIVERY_CODE = 'shipment_sent_delivery_code';

    /** Sent to the courier, not the customer: "this delivery is yours". */
    case SHIPMENT_ASSIGNED_DELIVERY = 'shipment_assigned_delivery';
}
