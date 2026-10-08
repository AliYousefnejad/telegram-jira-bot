<?php

namespace App\Http\Requests\WebApp;

use App\Enums\TaskImpact;
use App\Enums\TaskOwner;
use App\Enums\TaskSeverity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** The edits staged on a task in the Mini App; any subset of the fields, at least one. */
class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ValidateTelegramInitData has already admitted a linked bot admin
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'required', 'string', 'max:20', 'regex:/^\d+$/'],
            'assignee' => ['sometimes', 'nullable', 'string', 'max:255'],
            'owner' => ['sometimes', 'required', Rule::in(TaskOwner::values())],
            'severity' => ['sometimes', 'required', Rule::in(TaskSeverity::values())],
            'impact' => ['sometimes', 'required', Rule::in(TaskImpact::values())],
            'category' => ['sometimes', 'required', 'string', 'max:20', 'regex:/^\d+$/'],
            'description' => ['sometimes', 'required', 'string', 'min:5', 'max:30000'],
            'comment' => ['sometimes', 'required', 'string', 'min:2', 'max:30000'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_intersect(array_keys($this->all()), array_keys($this->rules())) === []) {
                $validator->errors()->add('edits', 'Nothing to change.');
            }
        }];
    }
}
