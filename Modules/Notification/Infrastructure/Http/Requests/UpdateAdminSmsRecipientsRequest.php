<?php

declare(strict_types=1);

namespace Modules\Notification\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAdminSmsRecipientsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('notification.admin-sms-recipients.manage');
    }

    public function rules(): array
    {
        return [
            // `present` rather than `required`: an empty array is a legitimate
            // instruction — "nobody receives this SMS" — and `required` would
            // reject it.
            'user_ids' => ['present', 'array'],
            // No `exists:users,id`: that would be a Notification query against
            // Identity's table. Whether each id names an *administrator* is
            // answered by IdentityManagerInterface inside the action, which
            // returns 422 on the same field.
            'user_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }

    /** @return list<int> */
    public function userIds(): array
    {
        return array_values(array_map('intval', (array) $this->validated('user_ids')));
    }
}
