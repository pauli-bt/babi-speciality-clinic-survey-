<?php
declare(strict_types=1);

namespace App\Validation;

use App\Helpers\Phone;
use App\Models\Reference;

final class CustomerValidator
{
    /** @return array{name: ?string, phone: ?string, phone_normalized: ?string, errors: string[]} */
    public static function contact(string $name, string $phone): array
    {
        $errors = [];
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $phone = trim($phone);
        $norm = null;
        if ($name === '' && $phone === '') {
            $errors[] = 'Enter the customer\'s name or telephone number (at least one is required).';
        }
        if ($name !== '' && (mb_strlen($name) < 2 || mb_strlen($name) > 150 || preg_match('/[\x00-\x1F\x7F]/', $name))) {
            $errors[] = 'Name must be 2 to 150 characters.';
        }
        if ($phone !== '') {
            if (!preg_match('/^[0-9 +().\-]{5,30}$/', $phone)) {
                $errors[] = 'Telephone number may contain only digits, spaces and + ( ) . -';
            } else {
                $norm = Phone::normalize($phone);
                if (strlen($norm) < 7 || strlen($norm) > 15) {
                    $errors[] = 'Telephone number must have 7 to 15 digits.';
                    $norm = null;
                }
            }
        }
        return ['name' => $name === '' ? null : $name, 'phone' => $phone === '' ? null : $phone, 'phone_normalized' => $norm, 'errors' => $errors];
    }

    /** @return array{source_id: int, platform_id: ?int, source_other: ?string, campaign_id: ?int, errors: string[]} */
    public static function attribution(array $in): array
    {
        $errors = [];
        $sourceId = (int) ($in['source_id'] ?? 0);
        $platformId = (int) ($in['platform_id'] ?? 0);
        $other = trim((string) ($in['source_other'] ?? ''));
        $campaignId = (int) ($in['campaign_id'] ?? 0);
        $source = $sourceId > 0 ? Reference::source($sourceId) : null;
        if (!$source || (int) $source['is_active'] !== 1) {
            return ['source_id' => 0, 'platform_id' => null, 'source_other' => null, 'campaign_id' => null, 'errors' => ['Choose how the customer first heard about the clinic (or "Unknown").']];
        }
        $platform = null;
        if ((int) $source['requires_platform'] === 1) {
            $platform = $platformId > 0 ? Reference::platform($platformId) : null;
            if (!$platform) {
                $errors[] = 'Choose the social media platform.';
            }
        } elseif ($platformId > 0) {
            $errors[] = 'A platform can only be recorded when the source is social media.';
        }
        if ((int) $source['allows_other_text'] === 1) {
            if (mb_strlen($other) > 150) {
                $errors[] = 'The "Other" description must be at most 150 characters.';
            }
        } elseif ($other !== '') {
            $errors[] = 'A description can only be recorded when the source is "Other".';
        }
        $campaign = null;
        if ($campaignId > 0) {
            $campaign = Reference::campaign($campaignId);
            if (!$campaign) {
                $errors[] = 'That campaign is not available.';
            }
        }
        return [
            'source_id' => $sourceId,
            'platform_id' => $platform ? (int) $platform['id'] : null,
            'source_other' => ((int) $source['allows_other_text'] === 1 && $other !== '') ? $other : null,
            'campaign_id' => $campaign ? (int) $campaign['id'] : null,
            'errors' => $errors,
        ];
    }
}
