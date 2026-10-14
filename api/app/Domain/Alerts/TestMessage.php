<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use App\Domain\Alerts\Models\Delivery;
use App\Domain\Alerts\Models\WebhookEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A webhook.test message for one webhook (plan P5-i1 D4; T02 for a signed webhook, T03 for Discord). For a signed
 * webhook it carries no price, so it is the one payload whose timestamp is a clock time: the delivery's created_at,
 * and its text says so. For a Discord webhook the body is the Discord envelope with DiscordMessage::TEST_TEXT, on
 * channel discord. The delivery is due at once; the next sweep sends it like any other.
 */
final class TestMessage
{
    public const string TEXT = 'PokéPrices test message. It carries no price.';

    public static function queue(WebhookEndpoint $endpoint): Delivery
    {
        if ($endpoint->kind !== 'standard' && $endpoint->kind !== 'discord') {
            throw new InvalidArgumentException('A webhook.test message needs a signed or Discord webhook.');
        }
        $now = CarbonImmutable::now()->utc()->startOfSecond();
        $body = $endpoint->kind === 'discord' ? DiscordMessage::envelope(DiscordMessage::TEST_TEXT) : json_encode([
            'type' => 'webhook.test',
            'timestamp' => $now->format('Y-m-d\TH:i:s\Z'),
            'data' => ['message' => self::TEXT],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $delivery = new Delivery;
        $delivery->forceFill([
            'message_id' => 'msg_'.Str::ulid(),
            'user_id' => $endpoint->user_id,
            'kind' => 'webhook.test',
            'channel' => $endpoint->kind === 'discord' ? 'discord' : 'webhook',
            'endpoint_id' => $endpoint->id,
            'event_id' => null,
            'day' => null,
            'body' => $body,
            'next_attempt_at' => $now,
            'created_at' => $now,
        ])->save();

        return $delivery;
    }
}
