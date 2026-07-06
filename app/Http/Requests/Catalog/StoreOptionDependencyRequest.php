<?php

namespace App\Http\Requests\Catalog;

use App\Models\OptionDependency;
use App\Models\ServiceInputOption;
use App\Models\ServiceVersion;
use App\Support\Catalog\DependencyGraph;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreOptionDependencyRequest extends FormRequest
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
            'option_id' => ['required', 'string', Rule::exists('service_input_options', 'id')],
            'parent_option_id' => ['required', 'string', Rule::exists('service_input_options', 'id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Do not layer graph checks on top of malformed/absent ids.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var ServiceVersion $version */
            $version = $this->route('version');
            $optionId = (string) $this->input('option_id');
            $parentId = (string) $this->input('parent_option_id');

            // Both ends must live in THIS version — an edge may never cross a
            // version boundary.
            $versionOptionIds = self::optionIdsForVersion($version);
            foreach (['option_id' => $optionId, 'parent_option_id' => $parentId] as $field => $id) {
                if (! in_array($id, $versionOptionIds, true)) {
                    $validator->errors()->add($field, 'The option must belong to this version.');
                }
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($optionId === $parentId) {
                $validator->errors()->add('parent_option_id', 'An option cannot depend on itself.');

                return;
            }

            $alreadyExists = OptionDependency::query()
                ->where('option_id', $optionId)
                ->where('parent_option_id', $parentId)
                ->exists();
            if ($alreadyExists) {
                $validator->errors()->add('parent_option_id', 'This dependency already exists.');

                return;
            }

            if (DependencyGraph::optionEdgeCreatesCycle($optionId, $parentId)) {
                $validator->errors()->add(
                    'parent_option_id',
                    'This dependency would create a cycle in the option graph.',
                );
            }
        });
    }

    /**
     * @return array<int, string>
     */
    public static function optionIdsForVersion(ServiceVersion $version): array
    {
        return ServiceInputOption::query()
            ->whereHas('input', fn ($query) => $query->where('service_version_id', $version->id))
            ->pluck('id')
            ->all();
    }
}
