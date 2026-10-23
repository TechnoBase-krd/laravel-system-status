<?php

namespace Technobase\SystemStatus\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Technobase\SystemStatus\Enums\IncidentState;

class StoreIncidentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'state' => ['required', Rule::in(IncidentState::values())],
            'message' => ['required', 'string', 'max:2000'],
        ];
    }
}
