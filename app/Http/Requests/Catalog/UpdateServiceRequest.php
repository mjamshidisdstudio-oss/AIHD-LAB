<?php

namespace App\Http\Requests\Catalog;

use App\Enums\ServiceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'category' => ['sometimes', 'string', 'max:255'],
            'external_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            // Accepted as pasted and re-hashed by the model cast on save. Never
            // generated; omit the field to leave the existing secret untouched.
            'service_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(ServiceStatus::class)],
        ];
    }
}
