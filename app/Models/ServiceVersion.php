<?php

namespace App\Models;

use App\Enums\ServiceVersionStatus;
use Database\Factories\ServiceVersionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceVersion extends Model
{
    /** @use HasFactory<ServiceVersionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'service_id',
        'version_no',
        'status',
        'coin_cost',
        'regenerate_limit',
        'response_timeout_s',
        'get_interval_s',
        'max_get_attempts',
        'post_url',
        'get_url',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ServiceVersionStatus::class,
            'version_no' => 'integer',
            'coin_cost' => 'integer',
            'regenerate_limit' => 'integer',
            'response_timeout_s' => 'integer',
            'get_interval_s' => 'integer',
            'max_get_attempts' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return HasMany<ServiceInput, $this>
     */
    public function inputs(): HasMany
    {
        return $this->hasMany(ServiceInput::class);
    }

    /**
     * @return HasMany<ServiceOutput, $this>
     */
    public function outputs(): HasMany
    {
        return $this->hasMany(ServiceOutput::class);
    }

    /**
     * @return HasMany<ServiceWaitingText, $this>
     */
    public function waitingTexts(): HasMany
    {
        return $this->hasMany(ServiceWaitingText::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasMany<ServiceComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(ServiceComment::class);
    }

    /**
     * @return HasMany<ServiceVote, $this>
     */
    public function votes(): HasMany
    {
        return $this->hasMany(ServiceVote::class);
    }
}
