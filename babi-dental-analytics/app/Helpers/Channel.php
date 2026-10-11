<?php
declare(strict_types=1);

namespace App\Helpers;

/** The interaction channel is bound to the role, never taken from user input. */
final class Channel
{
    private const BY_ROLE = ['receptionist' => 'reception', 'call_operator' => 'phone', 'social_tracker' => 'social_dm'];
    public const LABELS = ['reception' => 'Reception', 'phone' => 'Telephone', 'social_dm' => 'Social media DM'];

    public const PATHS = ['reception' => '/reception/interactions', 'phone' => '/call-operator/interactions', 'social_dm' => '/social-media/interactions'];

    public static function formPath(string $channel): string
    {
        return self::PATHS[$channel] . '/new';
    }

    public static function forRole(string $roleCode): ?string
    {
        return self::BY_ROLE[$roleCode] ?? null;
    }
}
