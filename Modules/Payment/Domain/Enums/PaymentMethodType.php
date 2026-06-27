<?php

declare(strict_types=1);

namespace Modules\Payment\Domain\Enums;

enum PaymentMethodType: string
{
    case ONLINE = 'online';
    case IN_PERSON = 'in_person';
}
