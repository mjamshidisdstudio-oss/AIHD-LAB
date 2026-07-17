<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A version's label is bookkeeping metadata, not configuration -- validated
 * separately from StoreVersionRequest/UpdateVersionRequest so it can go
 * through a dedicated endpoint that bypasses ensureEditable().
 */
class UpdateVersionLabelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:255'],
        ];
    }
}
