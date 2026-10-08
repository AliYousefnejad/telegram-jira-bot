<?php

namespace App\Http\Requests\WebApp;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LinkRequest extends FormRequest
{
    /**
     * Never echo a Personal Access Token back in validation errors or old input.
     *
     * @var array<int, string>
     */
    protected $dontFlash = ['token'];

    public function authorize(): bool
    {
        return true; // ValidateTelegramInitData has already admitted a bot admin
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'method' => ['required', Rule::in(['email', 'pat'])],
            'email' => ['required_if:method,email', 'nullable', 'string', 'max:190'],
            'token' => ['required_if:method,pat', 'nullable', 'string', 'min:20', 'max:512'],
        ];
    }
}
