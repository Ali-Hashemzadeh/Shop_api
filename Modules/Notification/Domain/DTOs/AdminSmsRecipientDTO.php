<?php

declare(strict_types=1);

namespace Modules\Notification\Domain\DTOs;

/**
 * One row of the admin recipient picker: an administrator, plus whether they are
 * currently selected to receive a given notification by SMS.
 *
 * The identity fields are copied out of Identity's UserSummaryDTO — this module
 * never holds a User model, and the picker needs a name and a number to show.
 */
class AdminSmsRecipientDTO
{
    public function __construct(
        public readonly int $userId,
        public readonly ?string $name,
        public readonly ?string $lastName,
        public readonly ?string $phone,
        public readonly bool $enabled,
    ) {}
}
