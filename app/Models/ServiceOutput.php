<?php

namespace App\Models;

use App\Enums\ServiceOutputType;
use App\Models\Concerns\GuardsVersionEditable;
use Database\Factories\ServiceOutputFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceOutput extends Model
{
    /** @use HasFactory<ServiceOutputFactory> */
    use GuardsVersionEditable, HasFactory, HasUuids;

    protected function resolveOwningVersion(): ?ServiceVersion
    {
        return $this->version;
    }

    protected $fillable = [
        'service_version_id',
        'result_number',
        'type',
    ];

    protected function casts(): array
    {
        return [
            'type' => ServiceOutputType::class,
            'result_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ServiceVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ServiceVersion::class, 'service_version_id');
    }
}
