<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Turning an existing account into a delivery worker. The body is empty — the
 * target is the route user — but the request still owns authorization so an
 * unauthorized caller gets 403 before anything else runs.
 */
class GrantDeliveryRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('profile.assign-delivery');
    }

    public function rules(): array
    {
        return [];
    }
}
