<?php

namespace App\Models;

use App\Enums\EntryMode;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_ref',
        'service_id',
        'service_version_id',
        'status',
        'source',
        'entry_mode',
        'coins_charged',
        'coin_txn_ref',
        'regenerated_from_order_id',
        'root_order_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'source' => OrderSource::class,
            'entry_mode' => EntryMode::class,
            'coins_charged' => 'integer',
            'completed_at' => 'datetime',
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
     * @return BelongsTo<ServiceVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ServiceVersion::class, 'service_version_id');
    }

    /**
     * @return HasMany<OrderInput, $this>
     */
    public function inputs(): HasMany
    {
        return $this->hasMany(OrderInput::class);
    }

    /**
     * @return HasMany<File, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    /**
     * @return HasMany<Request, $this>
     */
    public function requests(): HasMany
    {
        return $this->hasMany(Request::class);
    }

    /**
     * @return HasMany<Interaction, $this>
     */
    public function interactions(): HasMany
    {
        return $this->hasMany(Interaction::class);
    }

    /**
     * The order this one was regenerated from (one step up the lineage).
     *
     * @return BelongsTo<Order, $this>
     */
    public function regeneratedFrom(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'regenerated_from_order_id');
    }

    /**
     * The original order at the root of the regeneration lineage.
     *
     * @return BelongsTo<Order, $this>
     */
    public function rootOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'root_order_id');
    }

    /**
     * Orders regenerated directly from this one.
     *
     * @return HasMany<Order, $this>
     */
    public function regenerations(): HasMany
    {
        return $this->hasMany(Order::class, 'regenerated_from_order_id');
    }
}
