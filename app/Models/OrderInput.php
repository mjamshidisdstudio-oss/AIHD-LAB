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
     * expects_scalar is derived from the input type in booted(), not mass-assigned.
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
            'expects_scalar' => 'boolean',
            'value_fill_count' => 'integer',
        ];
    }

    /**
     * Keep expects_scalar in lockstep with the input's type so the database
     * CHECK (value_fill_count = expects_scalar) can enforce "exactly one scalar
     * value for value-bearing inputs, none for the rest" — the app cannot set
     * the discriminator inconsistently with the actual input.
     */
    protected static function booted(): void
    {
        static::saving(function (OrderInput $orderInput): void {
            if ($orderInput->input_id === null) {
                return;
            }

            $input = $orderInput->relationLoaded('input')
                ? $orderInput->getRelation('input')
                : ServiceInput::query()->find($orderInput->input_id);

            if ($input instanceof ServiceInput) {
                $orderInput->expects_scalar = $input->type->isScalar();
            }
        });
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
