<?php

namespace App\Http\Requests\Catalog;

use App\Models\ServiceInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOptionRequest extends FormRequest
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
        /** @var ServiceInput $input */
        $input = $this->route('input');

        return [
            'slug' => [
                'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('service_input_options', 'slug')->where('input_id', $input->id),
            ],
            'label' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:32'],
            'icon' => ['nullable', 'string', 'max:64'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
