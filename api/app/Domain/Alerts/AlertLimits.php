<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

/**
 * The per-user webhook limits from config/alerts.php webhooks: the most signed webhooks an account
 * holds, and the most Discord webhooks. The one place either is read.
 */
final class AlertLimits
{
    public static function webhooks(): int
    {
        return (int) config('alerts.webhooks.limit', 5);
    }

    public static function discordWebhooks(): int
    {
        return (int) config('alerts.webhooks.discord_limit', 1);
    }
}
