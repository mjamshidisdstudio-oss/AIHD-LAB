<?php

namespace App\Http\Requests\Catalog;

use App\Models\ServiceInputOption;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOptionRequest extends FormRequest
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
        /** @var ServiceInputOption $option */
        $option = $this->route('option');

        return [
            'slug' => [
                'sometimes', 'string', 'max:255', 'alpha_dash',
                Rule::unique('service_input_options', 'slug')
                    ->where('input_id', $option->input_id)
                    ->ignore($option->id),
            ],
            'label' => ['sometimes', 'string', 'max:255'],
            'color' => ['sometimes', 'nullable', 'string', 'max:32'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:64'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
