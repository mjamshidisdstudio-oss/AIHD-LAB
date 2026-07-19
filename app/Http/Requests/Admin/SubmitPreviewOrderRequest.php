<?php

namespace App\Http\Requests\Admin;

use App\Enums\EntryMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitPreviewOrderRequest extends FormRequest
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
            'entry_mode' => ['sometimes', Rule::enum(EntryMode::class)],
            'answers' => ['sometimes', 'array'],
            // Format and size are enforced per-type by StoreMedia
            // (config/media.php) -- not here, so there is exactly one place
            // that policy lives.
            'files' => ['sometimes', 'array'],
            'files.*' => ['file'],
        ];
    }
}
