<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    /**
     * The caller must be able to manage the target account.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->target());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->target())],
            'role' => ['sometimes', 'required', Rule::enum(UserRole::class)],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /**
     * Safety rules that hold even if the rank rules change later.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $target = $this->target();
                $changesRole = $this->has('role') && $this->enum('role', UserRole::class) !== $target->role;
                $deactivates = $this->has('is_active') && ! $this->boolean('is_active');

                if ($this->user()->is($target)) {
                    if ($changesRole) {
                        $validator->errors()->add('role', 'You cannot change your own role.');
                    }

                    if ($deactivates) {
                        $validator->errors()->add('is_active', 'You cannot deactivate your own account.');
                    }
                }

                if ($target->hasRole(UserRole::SuperAdmin) && $target->is_active && ($changesRole || $deactivates)) {
                    $activeSuperAdmins = User::query()
                        ->where('role', UserRole::SuperAdmin)
                        ->where('is_active', true)
                        ->count();

                    if ($activeSuperAdmins <= 1) {
                        $validator->errors()->add($changesRole ? 'role' : 'is_active', 'The last active super admin cannot be demoted or deactivated.');
                    }
                }
            },
        ];
    }

    /**
     * The account being edited.
     */
    public function target(): User
    {
        return $this->route('user');
    }
}
