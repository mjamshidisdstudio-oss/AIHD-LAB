<?php

namespace App\Http\Requests\Catalog;

use App\Enums\ServiceInputType;
use App\Models\ServiceVersion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInputRequest extends FormRequest
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
        /** @var ServiceVersion $version */
        $version = $this->route('version');

        return [
            'slug' => [
                'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('service_inputs', 'slug')->where('service_version_id', $version->id),
            ],
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(ServiceInputType::class)],
            'required' => ['sometimes', 'boolean'],
            'multi_select' => ['sometimes', 'boolean'],
            'searchable' => ['sometimes', 'boolean'],
            'depends_on_input_id' => [
                'nullable',
                // A dependency may only target another input of the SAME version.
                Rule::exists('service_inputs', 'id')->where('service_version_id', $version->id),
            ],
            'depends_on_value' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'config' => ['nullable', 'array'],
        ];
    }
}
