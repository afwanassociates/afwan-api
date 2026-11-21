<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetUserPasswordRequest extends FormRequest
{
    /**
     * The caller must be able to manage the target account.
     */
    public function authorize(): bool
    {
        return $this->user()->can('resetPassword', $this->route('user'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, Password|string>>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', Password::min(8)],
        ];
    }
}
