<?php

namespace App\Http\Requests\Catalog;

use App\Enums\ServiceKind;
use App\Enums\ServiceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceRequest extends FormRequest
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
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('services', 'slug')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'kind' => ['required', Rule::enum(ServiceKind::class)],
            'external_url' => ['nullable', 'url', 'max:2048', 'required_if:kind,external'],
            'category' => ['required', 'string', 'max:255'],
            'service_secret' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(ServiceStatus::class)],
        ];
    }
}
