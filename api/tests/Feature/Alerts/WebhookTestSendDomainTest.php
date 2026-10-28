<?php

declare(strict_types=1);

use App\Domain\Alerts\Models\WebhookEndpoint;
use App\Domain\Alerts\WebhookEndpoints;
use App\Http\Problems\WebhookDisabled;
use App\Http\Problems\WebhookNotFound;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\AlertRows;
use Tests\Support\FakeHostResolver;

// Plan Q1-i4 T02 (a9, a10, Q1-i2 T03 Low 2): the test send finds its endpoint through Domain, the signed-webhook
// limit is read in one place for the list and the refusal, and a JSON body that is not UTF-8 answers 422 on kind.

const Q4T2_URL = 'https://hooks.example.com/pokeprices';

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    FakeHostResolver::install(['hooks.example.com' => ['203.0.113.10']]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

it('gives the owner an active endpoint to test, and refuses another person with webhook-not-found', function (): void {
    $owner = Accounts::user();
    $other = AlertRows::user('other@example.com');
    $id = AlertRows::endpoint($owner);
    $endpoints = app(WebhookEndpoints::class);

    $endpoint = $endpoints->testable($owner->id, $id);

    expect($endpoint)->toBeInstanceOf(WebhookEndpoint::class)
        ->and($endpoint->id)->toBe($id)
        ->and(fn () => $endpoints->testable($other->id, $id))->toThrow(WebhookNotFound::class)
        ->and(fn () => $endpoints->testable($owner->id, $id + 1000))->toThrow(WebhookNotFound::class);
});

it('refuses a disabled endpoint with webhook-disabled', function (): void {
    $owner = Accounts::user();
    $id = AlertRows::endpoint($owner, 'standard', true);

    expect(fn () => app(WebhookEndpoints::class)->testable($owner->id, $id))->toThrow(WebhookDisabled::class);
});

it('reads the signed-webhook limit once: the list shows it and the next registration is refused with it', function (): void {
    config(['alerts.webhooks.limit' => 2]);
    $user = Accounts::user();
    $as = fn () => AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY);

    $as()->postJson('/v1/webhooks', ['kind' => 'standard', 'url' => Q4T2_URL])->assertCreated();
    $as()->postJson('/v1/webhooks', ['kind' => 'standard', 'url' => Q4T2_URL])->assertCreated();
    $third = $as()->postJson('/v1/webhooks', ['kind' => 'standard', 'url' => Q4T2_URL]);
    $list = $as()->getJson('/v1/webhooks');

    expect($list->json('meta.limit'))->toBe(2)
        ->and($third->status())->toBe(422)
        ->and($third->json('errors'))->toBe(['url' => ['You can add at most 2 signed webhooks.']])
        ->and(DB::table('webhook_endpoints')->where('user_id', $user->id)->count())->toBe(2);
});

it('answers a JSON body that is not UTF-8 with 422 on errors.kind (the body reads as empty) and writes nothing (pinned as intended)', function (): void {
    AccountRows::signIn($this, Accounts::user());

    $response = $this->withHeaders(Accounts::FIRST_PARTY)->call('POST', '/v1/webhooks', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    ], '{"kind":"standard","url":"'.Q4T2_URL."/\xFF\"}");

    expect($response->getStatusCode())->toBe(422)
        ->and(array_keys((array) $response->json('errors')))->toBe(['kind', 'url'])
        ->and($response->json('errors.kind'))->toBe([WebhookEndpoints::KIND])
        ->and(DB::table('webhook_endpoints')->count())->toBe(0);
});
