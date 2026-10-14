<?php

declare(strict_types=1);

use App\Domain\Alerts\DiscordMessage;

// Plan P5-i1 T03 criterion 6 (D4): the Discord envelope, byte for byte, and content over 2000 characters cut on a
// line boundary with a "…and <k> more" line.

it('builds the envelope in the D4 key order with mentions turned off', function (): void {
    expect(DiscordMessage::envelope("PokéPrices line one\nline two"))
        ->toBe('{"content":"PokéPrices line one\nline two","username":"PokéPrices","allowed_mentions":{"parse":[]}}');
});

it('keeps content of exactly 2000 characters whole', function (): void {
    $text = str_repeat('é', 1000)."\n".str_repeat('a', 999);

    expect(DiscordMessage::content($text))->toBe($text);
});

it('cuts content over 2000 characters on a line boundary and says how many lines were left out', function (): void {
    $lines = [];
    for ($i = 1; $i <= 300; $i++) {
        $lines[] = sprintf('Card %03d · holo: ▲ +$1.00', $i);
    }

    $content = DiscordMessage::content(implode("\n", $lines));

    $kept = explode("\n", $content);
    $more = array_pop($kept);
    $k = 300 - count($kept);
    expect(mb_strlen($content))->toBeLessThanOrEqual(2000)
        ->and($kept)->toBe(array_slice($lines, 0, count($kept)))
        ->and($more)->toBe("…and {$k} more")
        ->and($k)->toBeGreaterThan(0)
        ->and(mb_strlen(implode("\n", array_slice($lines, 0, count($kept) + 1))."\n…and ".($k - 1).' more'))->toBeGreaterThan(2000);
});

it('appends the given suffix to the more line', function (): void {
    $content = DiscordMessage::content(implode("\n", array_fill(0, 400, 'a line of twenty one')), ': https://example.com/watchlist');

    expect($content)->toMatch('/\n…and [0-9]+ more: https:\/\/example\.com\/watchlist$/D')
        ->and(mb_strlen($content))->toBeLessThanOrEqual(2000);
});

it('shortens a single line longer than the limit instead of sending nothing', function (): void {
    $content = DiscordMessage::content(str_repeat('x', 2500)."\nsecond");

    expect(mb_strlen($content))->toBeLessThanOrEqual(2000)
        ->and($content)->toStartWith('xxxx')
        ->and($content)->toEndWith("\n…and 1 more");
});

it('accepts only Discord webhook URLs on the four Discord hosts', function (string $url, bool $valid): void {
    expect(DiscordMessage::isWebhookUrl($url))->toBe($valid);
})->with([
    ['https://discord.com/api/webhooks/123456789012345678/abcDEF_ghi-123', true],
    ['https://discordapp.com/api/webhooks/1/a', true],
    ['https://ptb.discord.com/api/webhooks/1/a', true],
    ['https://canary.discord.com/api/webhooks/1/a', true],
    ['https://evil.discord.com/api/webhooks/1/a', false],
    ['https://discord.com.example.com/api/webhooks/1/a', false],
    ['http://discord.com/api/webhooks/1/a', false],
    ['https://discord.com:8443/api/webhooks/1/a', false],
    ['https://user@discord.com/api/webhooks/1/a', false],
    ['https://discord.com/api/webhooks/1/a?wait=true', false],
    ['https://discord.com/api/webhooks/1/a#x', false],
    ['https://discord.com/api/webhooks/1/a/slack', false],
    ['https://discord.com/api/webhooks/x/a', false],
    ['https://discord.com/api/webhooks/1/a.b', false],
    ['https://discord.com/api/webhooks/1/', false],
    ["https://discord.com/api/webhooks/1/a\n", false],
]);
