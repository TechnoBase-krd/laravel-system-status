<?php

namespace Technobase\SystemStatus\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'postmortem' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
