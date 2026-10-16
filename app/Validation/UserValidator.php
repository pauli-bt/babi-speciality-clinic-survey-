<?php
declare(strict_types=1);

namespace App\Validation;

use App\Models\Role;

final class UserValidator
{
    public static function normalizeUsername(string $u): string
    {
        return strtolower(trim($u));
    }

    /** @return string[] */
    public static function profileErrors(string $name, string $email, int $roleId): array
    {
        $errors = [];
        $len = mb_strlen($name);
        if ($len < 2 || $len > 120) {
            $errors[] = 'Name must be between 2 and 120 characters.';
        }
        if ($email !== '' && (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            $errors[] = 'Email address is not valid.';
        }
        if (Role::find($roleId) === null) {
            $errors[] = 'Please choose a valid role.';
        }
        return $errors;
    }

    public static function usernameErrors(string $username): array
    {
        return preg_match('/^[a-z0-9._-]{3,40}$/', $username) === 1
            ? []
            : ['Username must be 3-40 characters: lowercase letters, numbers, dot, dash or underscore.'];
    }
}
