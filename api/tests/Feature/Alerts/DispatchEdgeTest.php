<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\AccountRows;
use Tests\Support\AlertRows;
use Tests\Support\CatalogCache;

// Reviewer edge cases for plan P5-i1 T01 (D2, D3, D4, D11, criteria 5-9): a competing dispatch winning between the
// read and the insert, a hostile payload, more than one INSERT chunk, the clock-guard boundary, the floor with mixed
// run statuses, a null ceiling, the reset's date forms, secret hygiene, and read-only toward every other table.

const P5E_VARIANT = 'base1-4:holo:-:-';
const P5E_OTHER = 'base1-2:holo:-:-';

beforeEach(function (): void {
    $GLOBALS['p5e_hook'] = null;
    $GLOBALS['p5e_names'] = [];
    // A first stub that never answers (returns null) but runs the test's hook on every catalog batch read, then
    // the shared PokéSearch fake; a second stub overrides names for the hostile-name test.
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(function (ClientRequest $request) {
        if (parse_url($request->url(), PHP_URL_PATH) === '/api/v1/cards') {
            $hook = $GLOBALS['p5e_hook'];
            if (is_callable($hook)) {
                $GLOBALS['p5e_hook'] = null;
                $hook();
            }
            if ($GLOBALS['p5e_names'] !== []) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $data = [];
                foreach (explode(',', (string) $query['ids']) as $id) {
                    $data[] = ($GLOBALS['p5e_names'][$id] ?? []) + AccountRows::card($id);
                }

                return Http::response(['data' => $data, 'missing' => [], 'seed' => ['ref' => AccountRows::SEED_REF, 'seeded_at' => '2026-10-11T20:05:45Z']]);
            }
        }

        return null;
    });
    $this->catalogPrefix = AccountRows::fakePokeSearch();
    AlertRows::captureLogs();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

afterEach(function (): void {
    CatalogCache::flush($this->catalogPrefix);
    unset($GLOBALS['p5e_hook'], $GLOBALS['p5e_names']);
});

/** One eligible watcher of $variant with one standard endpoint; returns [user, endpoint id]. */
function p5eWatcher(string $email, string $variant = P5E_VARIANT): array
{
    $user = AlertRows::user($email);
    AccountRows::watch($user, $variant);

    return [$user, AlertRows::endpoint($user)];
}

it('keeps a competing dispatch\'s delivery untouched and creates only the missing ones when it wins between the read and the insert', function (): void {
    AlertRows::runs('2026-10-12');
    $event = AlertRows::event(P5E_VARIANT, '2026-10-12');
    [$user, $first] = p5eWatcher('racer@example.com');
    $second = AlertRows::endpoint($user);
    $GLOBALS['p5e_hook'] = function () use ($user, $event, $first): void {
        // The rival dispatch commits endpoint one's delivery after this run decided it was missing.
        DB::insert(
            "INSERT INTO deliveries (message_id, user_id, kind, channel, endpoint_id, event_id, day, body, next_attempt_at) VALUES ('msg_RIVAL', ?, 'price.moved', 'webhook', ?, ?, '2026-10-12', 'rival-body', now())",
            [$user->id, $first, $event],
        );
    };

    [$code, $line] = AlertRows::dispatch();

    expect($code)->toBe(0)
        ->and($line['created'])->toBe(['price.moved' => 1, 'alert.triggered' => 0, 'digest' => 0])
        ->and(AlertRows::moved())->toBe([[$event, $first], [$event, $second]])
        ->and(DB::table('deliveries')->where('endpoint_id', $first)->pluck('body', 'message_id')->all())->toBe(['msg_RIVAL' => 'rival-body']);
});

it('writes a hostile card name, extreme money and a 2^53+1 event id into the body byte for byte', function (): void {
    AlertRows::runs('2026-10-12');
    AccountRows::variant(P5E_VARIANT);
    DB::insert(<<<'SQL'
        INSERT INTO price_events (id, type, rule, variant_key, card_id, source, day, series_epoch, direction, close, previous_close, previous_day, change, change_pct, as_of, previous_as_of, ingest_run_id)
        OVERRIDING SYSTEM VALUE
        VALUES (9007199254740993, 'price.moved', 'draft-v1', ?, 'base1-4', 'tcgplayer', '2026-10-12', 2147483647, 'down', '0.01', '9999999999.99', '2026-10-11', '-9999999999.98', '-99.99', '2026-10-12 20:05:45+00', '2026-10-11 20:05:45+00', 1)
        SQL, [P5E_VARIANT]);
    $GLOBALS['p5e_names'] = ['base1-4' => ['name' => "Ptest \"Ex\" \\ / é ☃ 日本 🔥\u{2028} <b>&amp;", 'set_name' => 'Set \'1\' / "2"', 'number' => '4/102']];
    p5eWatcher('hostile@example.com');

    AlertRows::dispatch();
    $body = (string) DB::table('deliveries')->value('body');

    $expected = <<<'JSON'
        {"type":"price.moved","timestamp":"2026-10-12T20:05:45Z","data":{"event_id":"evt_9007199254740993","variant_key":"base1-4:holo:-:-","card_id":"base1-4","card":{"name":"Ptest \"Ex\" \\ / é ☃ 日本 🔥\u2028 <b>&amp;","set_name":"Set '1' / \"2\"","number":"4/102"},"direction":"down","close":"0.01","previous_close":"9999999999.99","change":"-9999999999.98","change_pct":"-99.99","day":"2026-10-12","as_of":"2026-10-12T20:05:45Z","previous_day":"2026-10-11","previous_as_of":"2026-10-11T20:05:45Z","series_epoch":2147483647,"rule":"draft-v1","source":"tcgplayer","feed":"tcgcsv","basis":"market","currency":"USD","cadence":"daily"}}
        JSON;

    expect($body)->toBe($expected)
        ->and(json_decode($body, true, 8, JSON_THROW_ON_ERROR)['data']['card']['name'])->toBe("Ptest \"Ex\" \\ / é ☃ 日本 🔥\u{2028} <b>&amp;")
        ->and(AlertRows::days())->toBe([['tcgplayer', '2026-10-12', 9007199254740993]]);
});

it('creates 1001 deliveries for one event over three INSERT chunks, once each, with distinct message ids', function (): void {
    AlertRows::runs('2026-10-12');
    $event = AlertRows::event(P5E_VARIANT, '2026-10-12');
    $user = AlertRows::user('many@example.com');
    AccountRows::watch($user, P5E_VARIANT);
    $rows = [];
    for ($i = 0; $i < 1001; $i++) {
        $rows[] = ['user_id' => $user->id, 'kind' => 'standard', 'url' => 'x', 'url_hint' => 'x', 'secret' => 'x'];
    }
    DB::table('webhook_endpoints')->insert($rows);

    [, $first] = AlertRows::dispatch();
    [, $second] = AlertRows::dispatch();

    expect($first['created'])->toBe(['price.moved' => 1001, 'alert.triggered' => 0, 'digest' => 0])
        ->and($second['created'])->toBe(['price.moved' => 0, 'alert.triggered' => 0, 'digest' => 0])
        ->and(DB::table('deliveries')->count())->toBe(1001)
        ->and(DB::table('deliveries')->distinct()->count('message_id'))->toBe(1001)
        ->and(DB::table('deliveries')->distinct()->count('endpoint_id'))->toBe(1001)
        ->and(DB::table('deliveries')->where('event_id', $event)->distinct()->count('body'))->toBe(1);
});

it('counts a run dated the current UTC day and ignores the next day\'s, whatever offset the clock carries, until midnight UTC', function (): void {
    AlertRows::runs('2026-10-11', '2026-10-12', '2026-10-13');
    $today = AlertRows::event(P5E_VARIANT, '2026-10-12');
    $tomorrow = AlertRows::event(P5E_OTHER, '2026-10-13');
    $user = AlertRows::user('clock@example.com');
    AccountRows::watch($user, P5E_VARIANT);
    AccountRows::watch($user, P5E_OTHER);
    $endpoint = AlertRows::endpoint($user);

    // 01:30 on the 13th at +05:00 is 20:30 UTC on the 12th.
    $this->travelTo(CarbonImmutable::parse('2026-10-13 01:30:00+05:00'));
    [, $offset] = AlertRows::dispatch();
    $afterOffset = AlertRows::moved();
    $warned = array_map(fn (array $r): array => $r['context']['days'], array_values(array_filter(AlertRows::logged('alerts_dispatch_skipped'), fn (array $r): bool => $r['context']['reason'] === 'future-dated run ignored')));

    $this->travelTo(CarbonImmutable::parse('2026-10-13 00:00:00', 'UTC'));
    [, $midnight] = AlertRows::dispatch();

    expect($offset)->toMatchArray(['deliverable_day' => '2026-10-12', 'newest_day' => '2026-10-12', 'floor' => '2026-10-12'])
        ->and($afterOffset)->toBe([[$today, $endpoint]])
        ->and($warned)->toBe([['2026-10-13']])
        ->and($midnight)->toMatchArray(['deliverable_day' => '2026-10-13', 'watermark' => '2026-10-12', 'passed_over' => [], 'skipped' => null])
        ->and(AlertRows::moved())->toBe([[$today, $endpoint], [$tomorrow, $endpoint]]);
});

it('lets the floor follow superseded runs but never failed, running, refused or skipped_unchanged ones', function (string $status, bool $blocks): void {
    AlertRows::run('2026-10-11');
    AlertRows::run('2026-10-12', $status);
    AlertRows::run('2026-10-13', $status);
    $event = AlertRows::event(P5E_VARIANT, '2026-10-11');
    [, $endpoint] = p5eWatcher('floor@example.com');

    [, $line] = AlertRows::dispatch();

    expect($line['floor'])->toBe($blocks ? '2026-10-13' : '2026-10-11')
        ->and($line['deliverable_day'])->toBe($blocks ? null : '2026-10-11')
        ->and(AlertRows::moved())->toBe($blocks ? [] : [[$event, $endpoint]]);
})->with([
    'superseded' => ['superseded', true],
    'failed' => ['failed', false],
    'running' => ['running', false],
    'refused' => ['refused', false],
    'skipped_unchanged' => ['skipped_unchanged', false],
]);

it('delivers nothing for a day recorded with a null ceiling even when events appear, until the operator reset re-opens it', function (): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    p5eWatcher('nullceiling@example.com');

    [, $empty] = AlertRows::dispatch();
    $event = AlertRows::event(P5E_VARIANT, '2026-10-12');
    [, $later] = AlertRows::dispatch();
    $before = [AlertRows::days(), AlertRows::moved()];
    [$resetCode, $reset] = AlertRows::dispatch(['--reset-watermark' => '2026-10-11']);
    [, $reopened] = AlertRows::dispatch();

    expect($empty)->toMatchArray(['deliverable_day' => '2026-10-12', 'ceiling' => null, 'events' => 0])
        ->and($later)->toMatchArray(['ceiling' => null, 'events' => 0, 'scoped_events' => 0, 'created' => ['price.moved' => 0, 'alert.triggered' => 0, 'digest' => 0]])
        ->and($before)->toBe([[['tcgplayer', '2026-10-12', null]], []])
        ->and($resetCode)->toBe(0)
        ->and($reset)->toBe(['reset_after' => '2026-10-11', 'deleted' => 1])
        ->and($reopened)->toMatchArray(['ceiling' => $event, 'events' => 1, 'created' => ['price.moved' => 1, 'alert.triggered' => 0, 'digest' => 0]])
        ->and(AlertRows::days())->toBe([['tcgplayer', '2026-10-12', $event]]);
});

it('refuses a malformed or impossible reset date with exit 1 and deletes nothing', function (string $value): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    p5eWatcher('reset@example.com');
    AlertRows::event(P5E_VARIANT, '2026-10-12');
    AlertRows::dispatch();
    $before = AlertRows::days();

    [$code, , $output] = AlertRows::dispatch(['--reset-watermark' => $value]);

    expect($code)->toBe(1)
        ->and(AlertRows::days())->toBe($before)
        ->and($output)->not->toContain('"deleted"');
})->with([
    'impossible day' => ['2026-02-30'],
    'month 13' => ['2026-13-01'],
    'no dashes' => ['20261011'],
    'short parts' => ['2026-1-1'],
    'empty' => [''],
    'leading space' => [' 2026-10-11'],
    'trailing space' => ['2026-10-11 '],
    'timestamp' => ['2026-10-11T00:00:00Z'],
    'word' => ['yesterday'],
    'trailing newline' => ["2026-10-11\n"],
]);

it('puts no URL, signing secret, address, or endpoint detail in the command output, the logs or the stored bodies', function (): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    AlertRows::event(P5E_VARIANT, '2026-10-12');
    p5eWatcher('sentinel-person@example.com');
    AlertRows::run('2026-10-20');
    AccountRows::$down = true;

    [, , $output] = AlertRows::dispatch();
    AccountRows::$down = false;
    [, , $again] = AlertRows::dispatch();
    $everything = $output.$again.json_encode(AlertRows::$logs).implode('', DB::table('deliveries')->pluck('body')->all());

    foreach (['test-0000', 'hooks.example.com', 'whsec'.'_', AlertRows::secret(), 'sentinel-person', 'https://', 'webhook-signature'] as $sentinel) {
        expect(str_contains($everything, $sentinel))->toBeFalse("leaked {$sentinel}");
    }
    expect(AlertRows::$logs)->not->toBe([]);
});

it('changes no row of any table except deliveries and dispatch_days', function (): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    AlertRows::event(P5E_VARIANT, '2026-10-12');
    AlertRows::event(P5E_OTHER, '2026-10-12');
    DB::table('movement_volume')->insert(['day' => '2026-10-12', 'source' => 'tcgplayer', 'rule' => 'draft-v1', 'pct' => '5.00', 'abs' => '1.00', 'compared' => 9, 'up' => 2, 'down' => 0, 'epoch_breaks' => 0, 'filled_skipped' => 0, 'extreme' => 0, 'sub_2_previous' => 0]);
    p5eWatcher('readonly@example.com');
    $holder = AlertRows::user('holder@example.com');
    AccountRows::hold($holder, P5E_OTHER, 3);
    AlertRows::endpoint($holder);
    $tables = array_map(
        fn (object $t): string => (string) $t->tablename,
        DB::select("SELECT tablename FROM pg_tables WHERE schemaname = current_schema() AND tablename NOT IN ('deliveries', 'dispatch_days', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs') ORDER BY 1"),
    );
    $snapshot = function () use ($tables): array {
        $sums = [];
        foreach ($tables as $table) {
            $sums[$table] = (string) DB::selectOne("SELECT md5(coalesce(string_agg(t::text, '|' ORDER BY t::text), '')) AS m FROM \"{$table}\" t")->m;
        }

        return $sums;
    };
    $before = $snapshot();

    [, $line] = AlertRows::dispatch();

    expect($line['created'])->toBe(['price.moved' => 2, 'alert.triggered' => 0, 'digest' => 0])
        ->and(count($tables))->toBeGreaterThan(10)
        ->and($snapshot())->toBe($before)
        ->and(DB::table('price_events')->whereNotNull('dispatched_at')->count())->toBe(0);
});

it('does not dispatch when --reset-watermark is given without a date', function (): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    AlertRows::event(P5E_VARIANT, '2026-10-12');
    p5eWatcher('bare@example.com');

    $code = Artisan::call('pokeprices:alerts:dispatch --reset-watermark');

    expect($code)->toBe(1)
        ->and(AlertRows::moved())->toBe([]);
});
