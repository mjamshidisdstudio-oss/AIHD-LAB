<?php

namespace App\Models;

use Database\Factories\OrderInputOptionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderInputOption extends Model
{
    /** @use HasFactory<OrderInputOptionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'order_input_id',
        'option_id',
    ];

    /**
     * @return BelongsTo<OrderInput, $this>
     */
    public function orderInput(): BelongsTo
    {
        return $this->belongsTo(OrderInput::class);
    }

    /**
     * @return BelongsTo<ServiceInputOption, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(ServiceInputOption::class, 'option_id');
    }
}
