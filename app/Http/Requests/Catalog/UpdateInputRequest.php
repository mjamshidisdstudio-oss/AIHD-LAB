<?php

namespace App\Http\Requests\Catalog;

use App\Models\ServiceInput;
use App\Support\Catalog\DependencyGraph;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Only re-check the graph when the edge itself is being changed.
            if (! $this->has('depends_on_input_id')) {
                return;
            }

            /** @var ServiceInput $input */
            $input = $this->route('input');
            $target = $this->input('depends_on_input_id');

            if (DependencyGraph::inputEdgeCreatesCycle($input, $target === null ? null : (string) $target)) {
                $validator->errors()->add(
                    'depends_on_input_id',
                    'This dependency would create a cycle in the input graph.',
                );
            }
        });
    }
}
