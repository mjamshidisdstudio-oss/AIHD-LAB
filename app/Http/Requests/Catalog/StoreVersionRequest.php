<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Attributes for creating or updating a draft version. All optional — a new
 * draft applies sensible defaults, and updates are partial.
 */
class StoreVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'coin_cost' => ['sometimes', 'integer', 'min:0'],
            'regenerate_limit' => ['sometimes', 'integer', 'min:0'],
            'response_timeout_s' => ['sometimes', 'integer', 'min:1'],
            'get_interval_s' => ['sometimes', 'integer', 'min:1'],
            'max_get_attempts' => ['sometimes', 'integer', 'min:1'],
            'post_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'get_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
        ];
    }
}
