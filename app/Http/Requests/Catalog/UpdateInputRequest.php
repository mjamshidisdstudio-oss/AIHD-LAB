<?php

namespace App\Http\Requests\Catalog;

use App\Models\ServiceInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInputRequest extends FormRequest
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
        $versionId = $input->service_version_id;

        return [
            'slug' => [
                'sometimes', 'string', 'max:255', 'alpha_dash',
                Rule::unique('service_inputs', 'slug')
                    ->where('service_version_id', $versionId)
                    ->ignore($input->id),
            ],
            'title' => ['sometimes', 'string', 'max:255'],
            'required' => ['sometimes', 'boolean'],
            'multi_select' => ['sometimes', 'boolean'],
            'searchable' => ['sometimes', 'boolean'],
            'depends_on_input_id' => [
                'sometimes', 'nullable',
                Rule::exists('service_inputs', 'id')
                    ->where('service_version_id', $versionId),
            ],
            'depends_on_value' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'config' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
