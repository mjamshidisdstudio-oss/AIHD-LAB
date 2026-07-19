<?php

namespace App\Http\Requests\Orders;

use App\Enums\EntryMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitOrderRequest extends FormRequest
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
            'service_id' => ['required', 'uuid', 'exists:services,id'],
            'entry_mode' => ['sometimes', Rule::enum(EntryMode::class)],
            // "Run again" from a completed order's results: chains the new
            // order into that order's regeneration lineage (see OrderController).
            'regenerated_from_order_id' => ['sometimes', 'uuid', 'exists:orders,id'],
            // Scalar / select answers keyed by input slug. Per-input requirements
            // (which inputs are mandatory) are enforced by SubmitOrder against the
            // published version.
            'answers' => ['sometimes', 'array'],
            // File answers keyed by input slug (image/video inputs). Format
            // and size are enforced per-type by StoreMedia (config/media.php)
            // -- not here, so there is exactly one place that policy lives.
            'files' => ['sometimes', 'array'],
            'files.*' => ['file'],
        ];
    }
}
