<?php

namespace App\Models;

use App\Models\Concerns\GuardsVersionEditable;
use Database\Factories\OptionDependencyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OptionDependency extends Model
{
    /** @use HasFactory<OptionDependencyFactory> */
    use GuardsVersionEditable, HasFactory, HasUuids;

    protected function resolveOwningVersion(): ?ServiceVersion
    {
        return $this->option?->input?->version;
    }

    protected $fillable = [
        'option_id',
        'parent_option_id',
    ];

    /**
     * @return BelongsTo<ServiceInputOption, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(ServiceInputOption::class, 'option_id');
    }

    /**
     * @return BelongsTo<ServiceInputOption, $this>
     */
    public function parentOption(): BelongsTo
    {
        return $this->belongsTo(ServiceInputOption::class, 'parent_option_id');
    }
}
