<?php

namespace App\Http\Requests\Catalog;

use App\Enums\ServiceOutputType;
use App\Models\ServiceOutput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOutputRequest extends FormRequest
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
        /** @var ServiceOutput $output */
        $output = $this->route('output');

        return [
            'result_number' => [
                'sometimes', 'integer', 'min:1',
                Rule::unique('service_outputs', 'result_number')
                    ->where('service_version_id', $output->service_version_id)
                    ->ignore($output->id),
            ],
            'type' => ['sometimes', Rule::enum(ServiceOutputType::class)],
        ];
    }
}
