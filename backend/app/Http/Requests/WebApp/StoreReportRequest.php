<?php

namespace App\Http\Requests\WebApp;

use App\Enums\ReportType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ValidateTelegramInitData has already admitted a linked admin
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $userBug = 'required_if:type,'.ReportType::UserBug->value;

        return [
            'type' => ['required', Rule::enum(ReportType::class)],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'user_name' => [$userBug, 'nullable', 'string', 'max:120'],
            'user_identifier' => [$userBug, 'nullable', 'string', 'max:60'],
            'user_mobile' => [$userBug, 'nullable', 'string', 'max:30'],
            'category' => ['nullable', 'string', 'max:20', 'regex:/^\d+$/'], // a Jira option id, see GET reports/options
            'assignee' => ['nullable', 'string', 'max:120'],
        ];
    }
}
