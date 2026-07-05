<?php

namespace App\Models;

use App\Enums\FileKind;
use Database\Factories\FileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class File extends Model
{
    /** @use HasFactory<FileFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'kind',
        'disk',
        'order_id',
        'mime',
        'path',
        'size',
    ];

    protected function casts(): array
    {
        return [
            'kind' => FileKind::class,
            'size' => 'integer',
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
     * @return HasMany<OrderInputFile, $this>
     */
    public function orderInputFiles(): HasMany
    {
        return $this->hasMany(OrderInputFile::class);
    }

    /**
     * @return HasMany<Result, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }
}
