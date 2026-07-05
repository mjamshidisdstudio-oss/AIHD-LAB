<?php

namespace App\Models;

use App\Enums\FailureStage;
use App\Enums\RequestStatus;
use Database\Factories\RequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Request extends Model
{
    /** @use HasFactory<RequestFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'order_id',
        'attempt_no',
        'external_order_id',
        'status',
        'failure_stage',
        'sent_at',
        'last_polled_at',
        'get_poll_count',
    ];

    protected function casts(): array
    {
        return [
            'status' => RequestStatus::class,
            'failure_stage' => FailureStage::class,
            'attempt_no' => 'integer',
            'get_poll_count' => 'integer',
            'sent_at' => 'datetime',
            'last_polled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return HasMany<Result, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
