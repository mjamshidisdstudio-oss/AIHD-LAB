<?php

namespace App\Models;

use App\Models\Concerns\GuardsVersionEditable;
use Database\Factories\ServiceWaitingTextFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceWaitingText extends Model
{
    /** @use HasFactory<ServiceWaitingTextFactory> */
    use GuardsVersionEditable, HasFactory, HasUuids;

    protected function resolveOwningVersion(): ?ServiceVersion
    {
        return $this->version;
    }

    protected $fillable = [
        'service_version_id',
        'text',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
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
