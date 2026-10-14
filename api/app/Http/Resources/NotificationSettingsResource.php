<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET and PUT /v1/me/notifications (plan P5-i1 D9): where alerts and the digest go (email, or the account's Discord
 * webhook), whether a daily digest is sent, and whether the account has an active Discord webhook to choose.
 */
final class NotificationSettingsResource extends JsonResource
{
    /** @param array{channel: string, digest: bool, discord_available: bool} $settings */
    public function __construct(array $settings)
    {
        parent::__construct($settings);
    }

    /** @return array{channel: 'email'|'discord', digest: bool, discord_available: bool} */
    public function toArray(Request $request): array
    {
        /** @var array{channel: string, digest: bool, discord_available: bool} $settings */
        $settings = $this->resource;

        return [
            'channel' => $settings['channel'] === 'discord' ? 'discord' : 'email',
            /** @var bool */
            'digest' => $settings['digest'],
            /** @var bool */
            'discord_available' => $settings['discord_available'],
        ];
    }
}
