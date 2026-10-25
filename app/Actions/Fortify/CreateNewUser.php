<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /** @param array<string, string> $input */
    public function create(array $input): User
    {
        $email = mb_strtolower(trim($input['email'] ?? ''));

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class, 'email'),
            ],
            'password' => $this->passwordRules(),
        ]);

        $validator->after(function ($validator) use ($email): void {
            if ($email !== '' && User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                $validator->errors()->add('email', 'Email sudah digunakan.');
            }
        });
        $validator->validate();

        return User::create([
            'name' => trim($input['name']),
            'email' => $email,
            'password' => $input['password'],
        ]);
    }
}
