<?php

namespace App\Models;

use App\Enums\ResultSource;
use App\Enums\ResultType;
use Database\Factories\ResultFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Result extends Model
{
    /** @use HasFactory<ResultFactory> */
    use HasFactory, HasUuids;

    /**
     * The result carries its own arrival timestamp, which doubles as the
     * creation time; there is no updated_at column.
     */
    const CREATED_AT = 'received_at';

    const UPDATED_AT = null;

    protected $fillable = [
        'request_id',
        'result_number',
        'type',
        'file_id',
        'text_value',
        'source',
        'latency_ms',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ResultType::class,
            'source' => ResultSource::class,
            'result_number' => 'integer',
            'latency_ms' => 'integer',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Request, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /**
     * @return HasMany<Interaction, $this>
     */
    public function interactions(): HasMany
    {
        return $this->hasMany(Interaction::class);
    }
}
