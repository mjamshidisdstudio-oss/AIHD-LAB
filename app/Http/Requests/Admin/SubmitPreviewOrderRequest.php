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
            'files' => ['sometimes', 'array'],
            'files.*' => ['file', 'max:10240'],
        ];
    }
}
