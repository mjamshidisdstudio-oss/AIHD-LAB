<?php

namespace App\Models;

use Database\Factories\OrderInputFileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderInputFile extends Model
{
    /** @use HasFactory<OrderInputFileFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'order_input_id',
        'file_id',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<OrderInput, $this>
     */
    public function orderInput(): BelongsTo
    {
        return $this->belongsTo(OrderInput::class);
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
