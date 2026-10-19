<?php

namespace Technobase\SystemStatus\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Technobase\SystemStatus\Actions\GetStatusSummaryAction;

/**
 * Query of the JSON API: `range` (default 1h, the smallest answer for a dashboard that polls
 * every minute) and `locale` for the labels (default: the application's locale).
 */
class ApiStatusRequest extends StatusSummaryRequest
{
    public function rules(): array
    {
        return [
            'range' => ['nullable', Rule::in(GetStatusSummaryAction::RANGES)],
            'locale' => ['nullable', 'string', 'alpha_dash', 'max:10'],
        ];
    }

    /**
     * Always a JSON error, even when the caller does not send `Accept: application/json`.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The given data was invalid.',
            'errors' => $validator->errors(),
        ], 422));
    }

    public function range(): string
    {
        return $this->validated('range') ?? '1h';
    }

    public function applyLocale(): void
    {
        if (filled($locale = $this->validated('locale'))) {
            app()->setLocale($locale);
        }
    }
}
