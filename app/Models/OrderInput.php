<?php

namespace App\Models;

use Database\Factories\OrderInputFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderInput extends Model
{
    /** @use HasFactory<OrderInputFactory> */
    use HasFactory, HasUuids;

    /**
     * value_fill_count is a stored generated column and must never be written.
     */
    protected $fillable = [
        'order_id',
        'input_id',
        'value_text',
        'value_bool',
    ];

    protected function casts(): array
    {
        return [
            'value_bool' => 'boolean',
            'value_fill_count' => 'integer',
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
     * @return BelongsTo<ServiceInput, $this>
     */
    public function input(): BelongsTo
    {
        return $this->belongsTo(ServiceInput::class, 'input_id');
    }

    /**
     * @return HasMany<OrderInputOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(OrderInputOption::class);
    }

    /**
     * @return HasMany<OrderInputFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(OrderInputFile::class);
    }

    /**
     * The catalog options this answer selected.
     *
     * @return BelongsToMany<ServiceInputOption, $this>
     */
    public function selectedOptions(): BelongsToMany
    {
        return $this->belongsToMany(
            ServiceInputOption::class,
            'order_input_options',
            'order_input_id',
            'option_id',
        )->withTimestamps();
    }

    /**
     * The uploaded files this answer attached, in position order.
     *
     * @return BelongsToMany<File, $this>
     */
    public function attachedFiles(): BelongsToMany
    {
        return $this->belongsToMany(
            File::class,
            'order_input_files',
            'order_input_id',
            'file_id',
        )->withPivot('position')->withTimestamps()->orderByPivot('position');
    }
}
