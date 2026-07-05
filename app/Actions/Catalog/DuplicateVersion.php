<?php

namespace App\Actions\Catalog;

use App\Enums\ServiceVersionStatus;
use App\Models\OptionDependency;
use App\Models\ServiceInput;
use App\Models\ServiceInputOption;
use App\Models\ServiceVersion;
use Illuminate\Support\Facades\DB;

/**
 * Deep-copy a service version into a NEW draft version. Every internal foreign
 * key is remapped so it points at the freshly-created rows — nothing in the new
 * version references the source version:
 *   - service_inputs.depends_on_input_id  -> the copied input
 *   - service_input_options.input_id      -> the copied input
 *   - option_dependencies.option_id AND parent_option_id -> the copied options
 * Outputs and waiting texts are copied too. The source version is untouched.
 */
class DuplicateVersion
{
    public function handle(ServiceVersion $source): ServiceVersion
    {
        return DB::transaction(function () use ($source) {
            $service = $source->service()->firstOrFail();
            $nextVersionNo = (int) $service->versions()->max('version_no') + 1;

            $new = $service->versions()->create([
                'version_no' => $nextVersionNo,
                'status' => ServiceVersionStatus::Draft,
                'coin_cost' => $source->coin_cost,
                'regenerate_limit' => $source->regenerate_limit,
                'response_timeout_s' => $source->response_timeout_s,
                'get_interval_s' => $source->get_interval_s,
                'max_get_attempts' => $source->max_get_attempts,
                'post_url' => $source->post_url,
                'get_url' => $source->get_url,
                'published_at' => null,
            ]);

            $sourceInputs = $source->inputs()->get();

            // Pass 1: copy inputs WITHOUT depends_on_input_id (it may reference an
            // input not yet created), recording source id -> new input model.
            /** @var array<string, ServiceInput> $inputMap */
            $inputMap = [];
            foreach ($sourceInputs as $srcInput) {
                $inputMap[$srcInput->id] = $new->inputs()->create([
                    'slug' => $srcInput->slug,
                    'title' => $srcInput->title,
                    'type' => $srcInput->type,
                    'required' => $srcInput->required,
                    'multi_select' => $srcInput->multi_select,
                    'searchable' => $srcInput->searchable,
                    'depends_on_input_id' => null,
                    'depends_on_value' => $srcInput->depends_on_value,
                    'sort_order' => $srcInput->sort_order,
                    'config' => $srcInput->config,
                ]);
            }

            // Pass 2: remap depends_on_input_id into the new version.
            foreach ($sourceInputs as $srcInput) {
                if ($srcInput->depends_on_input_id !== null
                    && isset($inputMap[$srcInput->depends_on_input_id])) {
                    $newInput = $inputMap[$srcInput->id];
                    $newInput->depends_on_input_id = $inputMap[$srcInput->depends_on_input_id]->id;
                    $newInput->save();
                }
            }

            // Copy options, recording source option id -> new option id.
            /** @var array<string, string> $optionMap */
            $optionMap = [];
            foreach ($sourceInputs as $srcInput) {
                foreach ($srcInput->options as $srcOption) {
                    $newOption = ServiceInputOption::create([
                        'input_id' => $inputMap[$srcInput->id]->id,
                        'slug' => $srcOption->slug,
                        'label' => $srcOption->label,
                        'color' => $srcOption->color,
                        'icon' => $srcOption->icon,
                        'sort_order' => $srcOption->sort_order,
                    ]);
                    $optionMap[$srcOption->id] = $newOption->id;
                }
            }

            // Copy option_dependencies, remapping BOTH the child and parent option.
            OptionDependency::query()
                ->whereIn('option_id', array_keys($optionMap))
                ->get()
                ->each(function (OptionDependency $dep) use ($optionMap) {
                    // Both ends live in the same version, so both are remapped.
                    if (isset($optionMap[$dep->option_id], $optionMap[$dep->parent_option_id])) {
                        OptionDependency::create([
                            'option_id' => $optionMap[$dep->option_id],
                            'parent_option_id' => $optionMap[$dep->parent_option_id],
                        ]);
                    }
                });

            foreach ($source->outputs as $output) {
                $new->outputs()->create([
                    'result_number' => $output->result_number,
                    'type' => $output->type,
                ]);
            }

            foreach ($source->waitingTexts as $waitingText) {
                $new->waitingTexts()->create([
                    'text' => $waitingText->text,
                    'sort_order' => $waitingText->sort_order,
                ]);
            }

            return $new->refresh();
        });
    }
}
