<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

/**
 * Standard Webhooks signatures: v1,<base64(HMAC-SHA256(key, "<id>.<timestamp>.<body>"))>. The body
 * and id stay the same on every attempt of a delivery; the timestamp, and so the signature, is fresh per attempt.
 */
final class WebhookSigner
{
    public static function sign(#[\SensitiveParameter] string $secret, string $messageId, int $timestamp, string $body): string
    {
        return 'v1,'.base64_encode(hash_hmac('sha256', "{$messageId}.{$timestamp}.{$body}", WebhookSecret::key($secret), true));
    }

    /**
     * The three signature headers of one attempt.
     *
     * @return array{'webhook-id': string, 'webhook-timestamp': string, 'webhook-signature': string}
     */
    public static function headers(#[\SensitiveParameter] string $secret, string $messageId, int $timestamp, string $body): array
    {
        return [
            'webhook-id' => $messageId,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => self::sign($secret, $messageId, $timestamp, $body),
        ];
    }
}
