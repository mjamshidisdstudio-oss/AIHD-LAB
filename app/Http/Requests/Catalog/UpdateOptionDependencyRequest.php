<?php

namespace App\Http\Requests\Catalog;

use App\Models\OptionDependency;
use App\Support\Catalog\DependencyGraph;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Repoint an existing option dependency's parent. The child option is fixed; only
 * the parent moves, and it must stay inside the same version and never close a
 * cycle (the edge being edited is excluded from the cycle walk).
 */
class UpdateOptionDependencyRequest extends FormRequest
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
            'parent_option_id' => ['required', 'string', Rule::exists('service_input_options', 'id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var OptionDependency $dependency */
            $dependency = $this->route('optionDependency');
            $version = $dependency->option?->input?->version;
            $optionId = (string) $dependency->option_id;
            $parentId = (string) $this->input('parent_option_id');

            $versionOptionIds = $version === null
                ? []
                : StoreOptionDependencyRequest::optionIdsForVersion($version);

            if (! in_array($parentId, $versionOptionIds, true)) {
                $validator->errors()->add('parent_option_id', 'The option must belong to this version.');

                return;
            }

            if ($optionId === $parentId) {
                $validator->errors()->add('parent_option_id', 'An option cannot depend on itself.');

                return;
            }

            if (DependencyGraph::optionEdgeCreatesCycle($optionId, $parentId, (string) $dependency->id)) {
                $validator->errors()->add(
                    'parent_option_id',
                    'This dependency would create a cycle in the option graph.',
                );
            }
        });
    }
}
