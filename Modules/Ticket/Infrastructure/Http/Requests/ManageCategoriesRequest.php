<?php

declare(strict_types=1);

namespace Modules\Ticket\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorize-only gate for admin category reads and deletes (`ticket.manage-categories`),
 * neither of which carries a body.
 */
class ManageCategoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('ticket.manage-categories');
    }

    public function rules(): array
    {
        return [];
    }
}
