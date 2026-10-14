<?php

declare(strict_types=1);

use App\Domain\Alerts\WebhookSecret;
use App\Domain\Alerts\WebhookSigner;

// Plan P5-i1 T02 criterion 1 (D5): Standard Webhooks signing. The reference vector is the published, non-secret
// Standard Webhooks example; its secret is assembled at run time from the prefix and the published key part.

it('reproduces the Standard Webhooks reference vector', function (): void {
    $reference = WebhookSecret::PREFIX.'MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

    expect(WebhookSigner::sign($reference, 'msg_p5jXN8AQM9LWM0D4loKWxJek', 1614265330, '{"test": 2432232314}'))
        ->toBe('v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=');
});

it('builds the three webhook headers, verifiable by an independent HMAC over id.timestamp.body', function (): void {
    $key = random_bytes(32);
    $secret = WebhookSecret::PREFIX.base64_encode($key);
    $body = '{"type":"webhook.test","data":{"message":"x"}}';

    $headers = WebhookSigner::headers($secret, 'msg_01J0000000000000000000000', 1760300000, $body);

    $expected = 'v1,'.base64_encode(hash_hmac('sha256', 'msg_01J0000000000000000000000.1760300000.'.$body, $key, true));
    expect($headers)->toBe([
        'webhook-id' => 'msg_01J0000000000000000000000',
        'webhook-timestamp' => '1760300000',
        'webhook-signature' => $expected,
    ]);
});

it('generates secrets in the Standard Webhooks format, each one different', function (): void {
    $a = WebhookSecret::generate();
    $b = WebhookSecret::generate();

    expect($a)->toMatch('/^whsec_[A-Za-z0-9+\/]{43}=$/')
        ->and($b)->toMatch('/^whsec_[A-Za-z0-9+\/]{43}=$/')
        ->and($a)->not->toBe($b)
        ->and(strlen(WebhookSecret::key($a)))->toBe(32);
});

it('refuses a secret without the prefix or with a key that is not base64', function (string $secret): void {
    expect(fn () => WebhookSecret::key($secret))->toThrow(InvalidArgumentException::class);
})->with([
    'no prefix' => [base64_encode(str_repeat("\0", 32))],
    'not base64' => ['whsec'.'_%%%%'],
    'empty key' => ['whsec'.'_'],
]);
