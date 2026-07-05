<?php

namespace App\Models;

use App\Enums\InteractionKind;
use Database\Factories\InteractionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Interaction extends Model
{
    /** @use HasFactory<InteractionFactory> */
    use HasFactory, HasUuids;

    /** Immutable event log: creation timestamp only. */
    const UPDATED_AT = null;

    protected $fillable = [
        'kind',
        'user_ref',
        'service_id',
        'order_id',
        'result_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => InteractionKind::class,
            'created_at' => 'datetime',
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
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Result, $this>
     */
    public function result(): BelongsTo
    {
        return $this->belongsTo(Result::class);
    }
}
