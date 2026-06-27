<?php

declare(strict_types=1);

namespace Modules\Payment\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InitializePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('payment.create');
    }

    public function rules(): array
    {
        return [
            'order_id' => ['required', 'integer'],
            'method_type' => ['required', 'string', 'in:online,in_person'],
            'gateway' => ['nullable', 'string'],
        ];
    }
}
