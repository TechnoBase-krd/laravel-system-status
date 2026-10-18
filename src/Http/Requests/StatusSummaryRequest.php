<?php

namespace Technobase\SystemStatus\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Technobase\SystemStatus\Actions\GetStatusSummaryAction;

class StatusSummaryRequest extends FormRequest
{
    /**
     * Access is limited by AuthorizeSystemStatus on the route.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'range' => ['nullable', Rule::in(GetStatusSummaryAction::RANGES)],
        ];
    }

    public function range(): string
    {
        return $this->validated('range') ?? '90d';
    }
}
