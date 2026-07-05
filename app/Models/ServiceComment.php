<?php

namespace App\Models;

use App\Enums\CommentSentiment;
use App\Enums\CommentStatus;
use Database\Factories\ServiceCommentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceComment extends Model
{
    /** @use HasFactory<ServiceCommentFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'service_version_id',
        'user_ref',
        'body',
        'sentiment',
        'status',
        'parent_id',
    ];

    protected function casts(): array
    {
        return [
            'sentiment' => CommentSentiment::class,
            'status' => CommentStatus::class,
        ];
    }

    /**
     * @return BelongsTo<ServiceVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ServiceVersion::class, 'service_version_id');
    }

    /**
     * @return BelongsTo<ServiceComment, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ServiceComment::class, 'parent_id');
    }

    /**
     * @return HasMany<ServiceComment, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(ServiceComment::class, 'parent_id');
    }
}
