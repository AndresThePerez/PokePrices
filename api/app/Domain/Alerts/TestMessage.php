<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use App\Domain\Alerts\Models\Delivery;
use App\Domain\Alerts\Models\WebhookEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A webhook.test message for one signed webhook (plan P5-i1 D4, T02; T03 adds the Discord envelope). It carries no
 * price, so it is the one payload whose timestamp is a clock time: the delivery's created_at, and its text says so.
 * The delivery is due at once; the next sweep sends it like any other.
 */
final class TestMessage
{
    public const string TEXT = 'PokéPrices test message. It carries no price.';

    public static function queue(WebhookEndpoint $endpoint): Delivery
    {
        if ($endpoint->kind !== 'standard') {
            throw new InvalidArgumentException('Only a signed webhook takes a webhook.test message here.');
        }
        $now = CarbonImmutable::now()->utc()->startOfSecond();
        $body = json_encode([
            'type' => 'webhook.test',
            'timestamp' => $now->format('Y-m-d\TH:i:s\Z'),
            'data' => ['message' => self::TEXT],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $delivery = new Delivery;
        $delivery->forceFill([
            'message_id' => 'msg_'.Str::ulid(),
            'user_id' => $endpoint->user_id,
            'kind' => 'webhook.test',
            'channel' => 'webhook',
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
