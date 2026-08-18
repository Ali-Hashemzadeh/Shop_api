<?php

declare(strict_types=1);

namespace Modules\Analytics\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('analytics.view');
    }

    public function rules(): array
    {
        return [];
    }
}
