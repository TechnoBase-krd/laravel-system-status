<?php

namespace Technobase\SystemStatus\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Technobase\SystemStatus\Enums\ComponentStatus;
use Technobase\SystemStatus\Enums\IncidentState;
use Technobase\SystemStatus\Support\StatusRegistry;

class StoreIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'component' => ['required', 'string', Rule::in(array_keys(app(StatusRegistry::class)->components()))],
            'severity' => ['required', Rule::in([ComponentStatus::Degraded->value, ComponentStatus::MajorOutage->value])],
            'state' => ['nullable', Rule::in([IncidentState::Investigating->value, IncidentState::Identified->value, IncidentState::Monitoring->value])],
            'title' => ['required', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:500'],
        ];
    }
}
