<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

/**
 * Why the address guard refused a URL: scheme (not https, and not an allow-listed http host:port),
 * invalid (malformed, user-info, a fragment, a host that is not a plain name or a canonical address), too_long (over
 * 2048 bytes), blocked_address, unresolvable. The value is what a delivery stores as last_error.
 */
enum GuardError: string
{
    case Scheme = 'scheme';
    case Invalid = 'invalid';
    case TooLong = 'too_long';
    case BlockedAddress = 'blocked_address';
    case Unresolvable = 'unresolvable';

    /** The errors.url message a registration shows for this refusal. */
    public function message(): string
    {
        return match ($this) {
            self::Scheme, self::Invalid => WebhookEndpoints::HTTPS,
            self::TooLong => WebhookEndpoints::TOO_LONG,
            self::BlockedAddress => WebhookEndpoints::PRIVATE,
            self::Unresolvable => WebhookEndpoints::UNRESOLVABLE,
        };
    }
}
