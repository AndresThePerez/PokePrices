<?php

declare(strict_types=1);

use App\Domain\Accounts\AccountLinks;
use App\Domain\Alerts\NotificationText;
use App\Models\User;

// The paths the API puts in its mails and notifications must be routes of the SPA (web/src/router.ts), or a
// link in a mail lands on the not-found page. Each path is taken from what the real builder emits.

/**
 * The routes of web/src/router.ts: its literal paths and the card pattern.
 *
 * @return array{paths: list<string>, card: string}
 */
function spaRoutes(?string $file = null): array
{
    $source = (string) file_get_contents($file ?? base_path('../web/src/router.ts'));
    preg_match_all("~(?:case |pathname === )'(/[^']*)'~", $source, $literals);
    preg_match('~const CARD = /(.+)/;~', $source, $card);

    return ['paths' => $literals[1], 'card' => '~'.($card[1] ?? '(?!)').'~'];
}

function spaPathOf(string $url): string
{
    return (string) parse_url($url, PHP_URL_PATH);
}

/** The one URL a notification text carries. */
function spaUrlIn(string $text): string
{
    preg_match('~https?://\S+~', $text, $m);

    return $m[0] ?? '';
}

function spaIsRoute(string $path, array $routes): bool
{
    return in_array($path, $routes['paths'], true) || preg_match($routes['card'], $path) === 1;
}

/** @return array<string, string> label => the path a real builder emitted */
function spaEmittedPaths(): array
{
    $event = (object) [
        'id' => 1, 'variant_key' => 'base1-4:holo:-:-', 'card_id' => 'base1-4', 'direction' => 'up', 'close' => '12.30',
        'previous_close' => '10.00', 'change' => '2.30', 'change_pct' => '23.00', 'day' => '2026-10-12',
        'as_of' => '2026-10-12T20:05:45Z', 'previous_day' => '2026-10-11', 'previous_as_of' => '2026-10-11T20:05:45Z',
        'series_epoch' => 1, 'rule' => 'draft-v1', 'source' => 'tcgplayer',
    ];
    $alert = NotificationText::alert($event, null, 10)['text'];
    $digest = NotificationText::digest('2026-10-12', [['event' => $event, 'card' => null]], 0)['text'];
    $user = new User;
    $user->forceFill(['id' => 7, 'email' => 'person@example.com']);

    return [
        'the card link of an alert' => spaPathOf(spaUrlIn($alert)),
        'the watchlist link of a digest' => spaPathOf(spaUrlIn($digest)),
        'the email verification link' => spaPathOf(AccountLinks::verification($user)),
        'the password reset link' => spaPathOf(AccountLinks::reset($user, 'example-token')),
    ];
}

it('finds the routes of the SPA router', function (): void {
    $routes = spaRoutes();

    expect($routes['paths'])->toContain('/watchlist', '/account/verify-email', '/password/reset')
        ->and(preg_match($routes['card'], '/card/base1-4'))->toBe(1);
});

it('builds every API link from a path that is a route of the SPA', function (): void {
    $routes = spaRoutes();

    foreach (spaEmittedPaths() as $label => $path) {
        expect($path)->not->toBe('', $label)
            ->and(spaIsRoute($path, $routes))->toBeTrue("{$label} ({$path}) is no route in web/src/router.ts");
    }
});

it('refuses a path that the router does not serve', function (): void {
    expect(spaIsRoute('/cards/base1-4', spaRoutes()))->toBeFalse()
        ->and(spaIsRoute('/account/verify', spaRoutes()))->toBeFalse();
});
