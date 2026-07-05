<?php

namespace App\Models;

use App\Enums\WebhookOutcome;
use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory, HasUuids;

    /**
     * Immutable audit record; received_at doubles as the creation timestamp
     * and there is no updated_at column.
     */
    const CREATED_AT = 'received_at';

    const UPDATED_AT = null;

    protected $fillable = [
        'service_id',
        'request_id',
        'external_order_id',
        'result_number',
        'outcome',
        'http_status',
        'raw_body',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => WebhookOutcome::class,
            'http_status' => 'integer',
            'result_number' => 'integer',
            'received_at' => 'datetime',
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
     * @return BelongsTo<Request, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }
}
