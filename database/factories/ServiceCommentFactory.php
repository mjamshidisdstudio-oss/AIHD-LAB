<?php

namespace Database\Factories;

use App\Enums\CommentSentiment;
use App\Enums\CommentStatus;
use App\Models\ServiceComment;
use App\Models\ServiceVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceComment>
 */
class ServiceCommentFactory extends Factory
{
    protected $model = ServiceComment::class;

    public function definition(): array
    {
        return [
            'service_version_id' => ServiceVersion::factory(),
            'user_ref' => $this->faker->uuid(),
            'body' => $this->faker->paragraph(),
            'sentiment' => $this->faker->randomElement(CommentSentiment::cases()),
            'status' => CommentStatus::Published,
            'parent_id' => null,
        ];
    }

    public function hidden(): static
    {
        return $this->state(fn () => ['status' => CommentStatus::Hidden]);
    }

    public function replyTo(ServiceComment $parent): static
    {
        return $this->state(fn () => [
            'parent_id' => $parent->getKey(),
            'service_version_id' => $parent->service_version_id,
        ]);
    }
}
