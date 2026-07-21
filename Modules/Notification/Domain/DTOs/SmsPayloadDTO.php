<?php

declare(strict_types=1);

namespace Modules\Notification\Domain\DTOs;

use Modules\Notification\Domain\Enums\NotificationTemplate;

/**
 * The SMS variant of a notification: which internal template to use and the
 * business parameters that fill it.
 *
 * The template is an internal constant (`NotificationTemplate`), never a raw
 * string from config or a client. Parameter names (`OrderId`, `TrackingCode`,
 * …) are likewise owned by us and provider-independent — the Sms module maps
 * both into whatever shape the active provider expects.
 *
 * Whether the active provider actually has a template id for this name is a
 * configuration question answered at send time; if it does not, the SMS is
 * skipped rather than failed.
 */
class SmsPayloadDTO
{
    /**
     * @param  array<string, scalar|null>  $parameters
     */
    public function __construct(
        public readonly NotificationTemplate $template,
        public readonly array $parameters = [],
    ) {}
}
