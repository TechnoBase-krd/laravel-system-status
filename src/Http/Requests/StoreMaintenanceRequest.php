<?php

namespace Technobase\SystemStatus\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Technobase\SystemStatus\Support\StatusRegistry;

class StoreMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:2000'],
            'components' => ['required', 'array', 'min:1'],
            'components.*' => ['string', Rule::in(array_keys(app(StatusRegistry::class)->components()))],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ];
    }
}
