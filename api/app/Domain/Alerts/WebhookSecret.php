<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use InvalidArgumentException;

/**
 * A Standard Webhooks signing secret: whsec_ followed by 32 random bytes, base64-encoded. The HMAC
 * key is the decoded part after the prefix. A secret is shown to its owner once and never logged.
 */
final class WebhookSecret
{
    public const string PREFIX = 'whsec_';

    public static function generate(): string
    {
        return self::PREFIX.base64_encode(random_bytes(32));
    }

    /** The HMAC key of a secret; refuses anything that is not the prefix and non-empty base64. */
    public static function key(#[\SensitiveParameter] string $secret): string
    {
        if (! str_starts_with($secret, self::PREFIX)) {
            throw new InvalidArgumentException('The signing secret has no whsec_ prefix.');
        }
        $key = base64_decode(substr($secret, strlen(self::PREFIX)), true);
        if ($key === false || $key === '') {
            throw new InvalidArgumentException('The signing secret is not base64.');
        }

        return $key;
    }
}
