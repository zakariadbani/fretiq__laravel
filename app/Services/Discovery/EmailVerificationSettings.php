<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Models\Campaign;
use App\Models\Setting;

final class EmailVerificationSettings
{
    public const ENABLED_KEY = 'delivrabilite.email_verification_enabled';

    public const DEFAULT_POLICY_KEY = 'delivrabilite.default_email_verification_policy';

    public function enabled(): bool
    {
        $default = (bool) config('prospecting.email_verification_enabled_default', true);

        try {
            return filter_var(Setting::get(self::ENABLED_KEY, $default), FILTER_VALIDATE_BOOL);
        } catch (\Throwable) {
            return $default;
        }
    }

    public function defaultCampaignPolicy(): string
    {
        try {
            $policy = (string) Setting::get(self::DEFAULT_POLICY_KEY, Campaign::VERIFICATION_VERIFIED_ONLY);
        } catch (\Throwable) {
            $policy = Campaign::VERIFICATION_VERIFIED_ONLY;
        }

        return in_array($policy, Campaign::EMAIL_VERIFICATION_POLICIES, true)
            ? $policy
            : Campaign::VERIFICATION_VERIFIED_ONLY;
    }
}
