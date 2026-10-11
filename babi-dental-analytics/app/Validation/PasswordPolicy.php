<?php
declare(strict_types=1);

namespace App\Validation;

final class PasswordPolicy
{
    /** @return string[] human-readable problems; empty means acceptable */
    public static function validate(string $password, string $username = ''): array
    {
        $errors = [];
        if (mb_strlen($password) < 12) {
            $errors[] = 'Password must be at least 12 characters long.';
        }
        if (strlen($password) > 72) {
            $errors[] = 'Password must be at most 72 bytes long.';
        }
        if (!preg_match('/\p{L}/u', $password) || !preg_match('/\d/', $password)) {
            $errors[] = 'Password must contain at least one letter and one number.';
        }
        if (strlen($username) >= 3 && stripos($password, $username) !== false) {
            $errors[] = 'Password must not contain the username.';
        }
        foreach (['password', 'qwerty', 'letmein', 'welcome', 'babi', 'admin', '123456', 'abcdef'] as $weak) {
            if (stripos($password, $weak) !== false) {
                $errors[] = 'Password is too easy to guess.';
                break;
            }
        }
        return $errors;
    }

    public static function generate(string $username = ''): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        do {
            $pw = '';
            for ($i = 0; $i < 16; $i++) {
                $pw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (self::validate($pw, $username) !== []);
        return $pw;
    }
}
