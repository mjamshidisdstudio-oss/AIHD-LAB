<?php

namespace App\Models;

use App\Enums\ServiceInputType;
use App\Models\Concerns\GuardsVersionEditable;
use Database\Factories\ServiceInputFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceInput extends Model
{
    /** @use HasFactory<ServiceInputFactory> */
    use GuardsVersionEditable, HasFactory, HasUuids;

    protected function resolveOwningVersion(): ?ServiceVersion
    {
        return $this->version;
    }

    protected $fillable = [
        'service_version_id',
        'slug',
        'title',
        'type',
        'required',
        'multi_select',
        'searchable',
        'depends_on_input_id',
        'depends_on_value',
        'sort_order',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'type' => ServiceInputType::class,
            'required' => 'boolean',
            'multi_select' => 'boolean',
            'searchable' => 'boolean',
            'sort_order' => 'integer',
            'config' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ServiceVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ServiceVersion::class, 'service_version_id');
    }

    /**
     * @return HasMany<ServiceInputOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(ServiceInputOption::class, 'input_id');
    }

    /**
     * The input that gates the visibility of this one.
     *
     * @return BelongsTo<ServiceInput, $this>
     */
    public function dependsOnInput(): BelongsTo
    {
        return $this->belongsTo(ServiceInput::class, 'depends_on_input_id');
    }

    /**
     * Inputs whose visibility is gated by this one.
     *
     * @return HasMany<ServiceInput, $this>
     */
    public function dependents(): HasMany
    {
        return $this->hasMany(ServiceInput::class, 'depends_on_input_id');
    }

    /**
     * @return HasMany<OrderInput, $this>
     */
    public function orderInputs(): HasMany
    {
        return $this->hasMany(OrderInput::class, 'input_id');
    }
}
