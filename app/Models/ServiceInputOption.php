<?php

namespace App\Models;

use App\Models\Concerns\GuardsVersionEditable;
use Database\Factories\ServiceInputOptionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceInputOption extends Model
{
    /** @use HasFactory<ServiceInputOptionFactory> */
    use GuardsVersionEditable, HasFactory, HasUuids;

    protected function resolveOwningVersion(): ?ServiceVersion
    {
        return $this->input?->version;
    }

    protected $fillable = [
        'input_id',
        'slug',
        'label',
        'color',
        'icon',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ServiceInput, $this>
     */
    public function input(): BelongsTo
    {
        return $this->belongsTo(ServiceInput::class, 'input_id');
    }

    /**
     * Options that must be selected for this option to become available.
     *
     * @return BelongsToMany<ServiceInputOption, $this>
     */
    public function parentOptions(): BelongsToMany
    {
        return $this->belongsToMany(
            ServiceInputOption::class,
            'option_dependencies',
            'option_id',
            'parent_option_id',
        )->withTimestamps();
    }

    /**
     * Options gated by the selection of this option.
     *
     * @return BelongsToMany<ServiceInputOption, $this>
     */
    public function dependentOptions(): BelongsToMany
    {
        return $this->belongsToMany(
            ServiceInputOption::class,
            'option_dependencies',
            'parent_option_id',
            'option_id',
        )->withTimestamps();
    }

    /**
     * @return HasMany<OptionDependency, $this>
     */
    public function dependencies(): HasMany
    {
        return $this->hasMany(OptionDependency::class, 'option_id');
    }

    /**
     * @return HasMany<OrderInputOption, $this>
     */
    public function orderInputOptions(): HasMany
    {
        return $this->hasMany(OrderInputOption::class, 'option_id');
    }
}
