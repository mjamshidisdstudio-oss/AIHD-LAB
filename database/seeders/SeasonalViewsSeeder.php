<?php

namespace Database\Seeders;

use App\Actions\Catalog\PublishVersion;
use App\Enums\ServiceInputType;
use App\Enums\ServiceKind;
use App\Enums\ServiceOutputType;
use App\Enums\ServiceStatus;
use App\Enums\ServiceVersionStatus;
use App\Models\OptionDependency;
use App\Models\Service;
use App\Models\ServiceInput;
use App\Models\ServiceInputOption;
use App\Models\ServiceOutput;
use App\Models\ServiceVersion;
use App\Models\ServiceWaitingText;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the built-in "Seasonal Views" service (slug: season-gen) with a single
 * published version: a room photo upload, a room type, a style whose options are
 * gated on the chosen room type, and an HD toggle, plus four image outputs and
 * three waiting messages.
 */
class SeasonalViewsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $service = Service::firstOrCreate(
                ['slug' => 'season-gen'],
                [
                    'name' => 'Seasonal Views',
                    'description' => 'Restyle a room photo into seasonal interior looks.',
                    'image_url' => 'https://placehold.co/512x512?text=Seasonal+Views',
                    'kind' => ServiceKind::Internal,
                    'external_url' => null,
                    'category' => 'interior',
                    'service_secret' => Str::random(48),
                    'status' => ServiceStatus::Active,
                ],
            );

            // Build the version as a draft so its inputs/options can be added
            // (published versions are frozen), then publish it at the end.
            $version = ServiceVersion::firstOrCreate(
                ['service_id' => $service->id, 'version_no' => 1],
                [
                    'status' => ServiceVersionStatus::Draft,
                    'coin_cost' => 2,
                    'regenerate_limit' => 3,
                    'response_timeout_s' => 120,
                    'get_interval_s' => 30,
                    'max_get_attempts' => 10,
                    'post_url' => 'https://api.example.test/season-gen/generate',
                    'get_url' => 'https://api.example.test/season-gen/result',
                    'published_at' => null,
                ],
            );

            // 1) room_photo — required image upload.
            $roomPhoto = ServiceInput::firstOrCreate(
                ['service_version_id' => $version->id, 'slug' => 'room_photo'],
                [
                    'title' => 'Room Photo',
                    'type' => ServiceInputType::Image,
                    'required' => true,
                    'sort_order' => 1,
                ],
            );

            // 2) room_type — select of bedroom/living/kitchen.
            $roomType = ServiceInput::firstOrCreate(
                ['service_version_id' => $version->id, 'slug' => 'room_type'],
                [
                    'title' => 'Room Type',
                    'type' => ServiceInputType::Select,
                    'required' => true,
                    'sort_order' => 2,
                ],
            );

            $roomTypeOptions = [];
            foreach ([
                ['slug' => 'bedroom', 'label' => 'Bedroom', 'icon' => 'bed'],
                ['slug' => 'living', 'label' => 'Living Room', 'icon' => 'sofa'],
                ['slug' => 'kitchen', 'label' => 'Kitchen', 'icon' => 'utensils'],
            ] as $i => $opt) {
                $roomTypeOptions[$opt['slug']] = ServiceInputOption::firstOrCreate(
                    ['input_id' => $roomType->id, 'slug' => $opt['slug']],
                    [
                        'label' => $opt['label'],
                        'icon' => $opt['icon'],
                        'sort_order' => $i + 1,
                    ],
                );
            }

            // 3) style — select gated on room_type (both at the input level via
            //    depends_on_input_id and per-option via option_dependencies).
            $style = ServiceInput::firstOrCreate(
                ['service_version_id' => $version->id, 'slug' => 'style'],
                [
                    'title' => 'Style',
                    'type' => ServiceInputType::Select,
                    'required' => true,
                    'sort_order' => 3,
                    'depends_on_input_id' => $roomType->id,
                ],
            );

            // Each style option is available only for a specific room type.
            $styleMap = [
                'cozy' => ['label' => 'Cozy', 'room' => 'bedroom'],
                'boho' => ['label' => 'Boho', 'room' => 'bedroom'],
                'modern' => ['label' => 'Modern', 'room' => 'living'],
                'industrial' => ['label' => 'Industrial', 'room' => 'living'],
                'farmhouse' => ['label' => 'Farmhouse', 'room' => 'kitchen'],
                'minimal' => ['label' => 'Minimal', 'room' => 'kitchen'],
            ];

            $sort = 1;
            foreach ($styleMap as $slug => $meta) {
                $styleOption = ServiceInputOption::firstOrCreate(
                    ['input_id' => $style->id, 'slug' => $slug],
                    ['label' => $meta['label'], 'sort_order' => $sort++],
                );

                OptionDependency::firstOrCreate([
                    'option_id' => $styleOption->id,
                    'parent_option_id' => $roomTypeOptions[$meta['room']]->id,
                ]);
            }

            // 4) hd — boolean toggle.
            ServiceInput::firstOrCreate(
                ['service_version_id' => $version->id, 'slug' => 'hd'],
                [
                    'title' => 'HD Output',
                    'type' => ServiceInputType::Boolean,
                    'required' => false,
                    'sort_order' => 4,
                ],
            );

            // Four image outputs.
            for ($n = 1; $n <= 4; $n++) {
                ServiceOutput::firstOrCreate(
                    ['service_version_id' => $version->id, 'result_number' => $n],
                    ['type' => ServiceOutputType::Image],
                );
            }

            // Three waiting messages.
            $waitingTexts = [
                'Analyzing your room…',
                'Dreaming up seasonal styles…',
                'Rendering your new views…',
            ];
            foreach ($waitingTexts as $i => $text) {
                ServiceWaitingText::firstOrCreate(
                    ['service_version_id' => $version->id, 'sort_order' => $i + 1],
                    ['text' => $text],
                );
            }

            // Publish the fully-built draft: sets published + published_at,
            // points the service's current_version_id at it, resets failures.
            if ($version->isDraft()) {
                app(PublishVersion::class)->handle($version);
            }
        });
    }
}
