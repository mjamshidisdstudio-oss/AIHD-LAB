<?php

namespace App\Http\Requests\Catalog;

use App\Enums\ServiceOutputType;
use App\Models\ServiceVersion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOutputRequest extends FormRequest
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
        /** @var ServiceVersion $version */
        $version = $this->route('version');

        return [
            'result_number' => [
                'required', 'integer', 'min:1',
                Rule::unique('service_outputs', 'result_number')
                    ->where('service_version_id', $version->id),
            ],
            'type' => ['required', Rule::enum(ServiceOutputType::class)],
        ];
    }
}
