<?php

declare(strict_types=1);

namespace Modules\Order\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexAdminOrdersRequest extends FormRequest
{
    /** Authorization is enforced by OrderPolicy in the controller. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string'],
            'order_id' => ['sometimes', 'integer'],
            'user_id' => ['sometimes', 'integer'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
