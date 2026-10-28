<?php

declare(strict_types=1);

use App\Domain\Alerts\GuardError;
use App\Domain\Alerts\WebhookEndpoints;

// Plan Q1-i4 T02 (A8): the address guard's refusals are a closed set of five cases, each with the errors.url message
// a registration shows; scheme and invalid both read as the https message.

it('names exactly the five guard refusals by their stored values', function (): void {
    expect(array_map(fn (GuardError $e): string => $e->value, GuardError::cases()))
        ->toBe(['scheme', 'invalid', 'too_long', 'blocked_address', 'unresolvable']);
});

it('gives every refusal a non-empty message, and the messages are exactly the four registration texts', function (): void {
    $messages = array_map(fn (GuardError $e): string => $e->message(), GuardError::cases());

    expect(array_filter($messages, fn (string $m): bool => $m === ''))->toBe([])
        ->and(array_combine(array_map(fn (GuardError $e): string => $e->value, GuardError::cases()), $messages))->toBe([
            'scheme' => WebhookEndpoints::HTTPS,
            'invalid' => WebhookEndpoints::HTTPS,
            'too_long' => WebhookEndpoints::TOO_LONG,
            'blocked_address' => WebhookEndpoints::PRIVATE,
            'unresolvable' => WebhookEndpoints::UNRESOLVABLE,
        ])
        ->and(array_values(array_unique($messages)))->toEqualCanonicalizing([
            WebhookEndpoints::HTTPS, WebhookEndpoints::PRIVATE, WebhookEndpoints::UNRESOLVABLE, WebhookEndpoints::TOO_LONG,
        ]);
});
