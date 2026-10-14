<?php

declare(strict_types=1);

use App\Domain\Alerts\Models\Delivery;
use App\Domain\Alerts\Models\WebhookEndpoint;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\AlertRows;

// Plan P5-i1 T01 criteria 2 and 3 (D1): webhook_endpoints, deliveries and dispatch_days with their exact columns,
// named constraints and indexes; the CHECKs and cascades; no reference to price_events, card_variants or any price
// table; the migration's round trip; the endpoint's url and secret stored encrypted and never serialised.

const P5_SCHEMA_MIGRATION = 'migrations/2026_10_16_000001_create_alert_delivery_tables.php';

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/** @return array<string, array{type: string, nullable: bool, default: string|null, identity: string}> */
function p5Columns(string $table): array
{
    $rows = DB::select('SELECT column_name, data_type, is_nullable, column_default, is_identity, identity_generation FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position', [$table]);
    $columns = [];
    foreach ($rows as $row) {
        $columns[$row->column_name] = [
            'type' => $row->data_type, 'nullable' => $row->is_nullable === 'YES', 'default' => $row->column_default,
            'identity' => $row->is_identity === 'YES' ? (string) $row->identity_generation : '',
        ];
    }

    return $columns;
}

/** @return array<string, string> constraint name => definition */
function p5Constraints(string $table): array
{
    $rows = DB::select('SELECT conname, pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conrelid = ?::regclass ORDER BY conname', [$table]);

    return collect($rows)->mapWithKeys(fn (object $r): array => [$r->conname => $r->def])->all();
}

/** @return array<string, string> index name => definition, for indexes that back no constraint, schema-free */
function p5Indexes(string $table): array
{
    $schema = (string) DB::selectOne('SELECT current_schema() AS s')->s;
    $rows = DB::select(<<<'SQL'
        SELECT c.relname AS name, pg_get_indexdef(i.indexrelid) AS def
        FROM pg_index i
        JOIN pg_class c ON c.oid = i.indexrelid
        WHERE i.indrelid = ?::regclass AND NOT EXISTS (SELECT 1 FROM pg_constraint k WHERE k.conindid = i.indexrelid)
        ORDER BY c.relname
        SQL, [$table]);

    return collect($rows)->mapWithKeys(fn (object $r): array => [$r->name => str_replace(" ON {$schema}.", ' ON ', (string) $r->def)])->all();
}

/** Runs $sql in a savepoint and returns the violated constraint's error text, or 'accepted' when nothing failed. */
function p5Rejects(string $sql): string
{
    try {
        DB::transaction(fn () => DB::statement($sql));
    } catch (QueryException $e) {
        return $e->getMessage();
    }

    return 'accepted';
}

it('creates webhook_endpoints exactly as D1', function (): void {
    expect(p5Columns('webhook_endpoints'))->toBe([
        'id' => ['type' => 'bigint', 'nullable' => false, 'default' => null, 'identity' => 'BY DEFAULT'],
        'user_id' => ['type' => 'bigint', 'nullable' => false, 'default' => null, 'identity' => ''],
        'kind' => ['type' => 'text', 'nullable' => false, 'default' => null, 'identity' => ''],
        'url' => ['type' => 'text', 'nullable' => false, 'default' => null, 'identity' => ''],
        'url_hint' => ['type' => 'text', 'nullable' => false, 'default' => null, 'identity' => ''],
        'secret' => ['type' => 'text', 'nullable' => true, 'default' => null, 'identity' => ''],
        'description' => ['type' => 'text', 'nullable' => true, 'default' => null, 'identity' => ''],
        'created_at' => ['type' => 'timestamp with time zone', 'nullable' => false, 'default' => 'now()', 'identity' => ''],
        'disabled_at' => ['type' => 'timestamp with time zone', 'nullable' => true, 'default' => null, 'identity' => ''],
    ])
        ->and(p5Constraints('webhook_endpoints'))->toBe([
            'webhook_endpoints_description_check' => 'CHECK (((description IS NULL) OR ((char_length(description) >= 1) AND (char_length(description) <= 100))))',
            'webhook_endpoints_kind_check' => "CHECK ((kind = ANY (ARRAY['standard'::text, 'discord'::text])))",
            'webhook_endpoints_pkey' => 'PRIMARY KEY (id)',
            'webhook_endpoints_secret_check' => "CHECK (((kind = 'standard'::text) = (secret IS NOT NULL)))",
            'webhook_endpoints_user_id_foreign' => 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
        ])
        ->and(p5Indexes('webhook_endpoints'))->toBe([
            'webhook_endpoints_one_discord_unique' => "CREATE UNIQUE INDEX webhook_endpoints_one_discord_unique ON webhook_endpoints USING btree (user_id) WHERE (kind = 'discord'::text)",
        ]);
});

it('creates deliveries exactly as D1', function (): void {
    expect(p5Columns('deliveries'))->toBe([
        'id' => ['type' => 'bigint', 'nullable' => false, 'default' => null, 'identity' => 'BY DEFAULT'],
        'message_id' => ['type' => 'text', 'nullable' => false, 'default' => null, 'identity' => ''],
        'user_id' => ['type' => 'bigint', 'nullable' => false, 'default' => null, 'identity' => ''],
        'kind' => ['type' => 'text', 'nullable' => false, 'default' => null, 'identity' => ''],
        'channel' => ['type' => 'text', 'nullable' => false, 'default' => null, 'identity' => ''],
        'endpoint_id' => ['type' => 'bigint', 'nullable' => true, 'default' => null, 'identity' => ''],
        'event_id' => ['type' => 'bigint', 'nullable' => true, 'default' => null, 'identity' => ''],
        'day' => ['type' => 'date', 'nullable' => true, 'default' => null, 'identity' => ''],
        'body' => ['type' => 'text', 'nullable' => false, 'default' => null, 'identity' => ''],
        'status' => ['type' => 'text', 'nullable' => false, 'default' => "'pending'::text", 'identity' => ''],
        'attempts' => ['type' => 'smallint', 'nullable' => false, 'default' => '0', 'identity' => ''],
        'claims' => ['type' => 'smallint', 'nullable' => false, 'default' => '0', 'identity' => ''],
        'next_attempt_at' => ['type' => 'timestamp with time zone', 'nullable' => false, 'default' => null, 'identity' => ''],
        'last_status' => ['type' => 'smallint', 'nullable' => true, 'default' => null, 'identity' => ''],
        'last_error' => ['type' => 'text', 'nullable' => true, 'default' => null, 'identity' => ''],
        'delivered_at' => ['type' => 'timestamp with time zone', 'nullable' => true, 'default' => null, 'identity' => ''],
        'created_at' => ['type' => 'timestamp with time zone', 'nullable' => false, 'default' => 'now()', 'identity' => ''],
    ])
        ->and(p5Constraints('deliveries'))->toBe([
            'deliveries_channel_check' => "CHECK ((channel = ANY (ARRAY['webhook'::text, 'discord'::text, 'email'::text])))",
            'deliveries_endpoint_id_foreign' => 'FOREIGN KEY (endpoint_id) REFERENCES webhook_endpoints(id) ON DELETE CASCADE',
            'deliveries_kind_check' => "CHECK ((kind = ANY (ARRAY['price.moved'::text, 'alert.triggered'::text, 'digest'::text, 'webhook.test'::text])))",
            'deliveries_last_error_check' => "CHECK (((last_error IS NULL) OR (last_error = ANY (ARRAY['connection'::text, 'timeout'::text, 'http_status'::text, 'redirect'::text, 'blocked_address'::text, 'unresolvable'::text, 'no_channel'::text, 'internal'::text]))))",
            'deliveries_message_id_unique' => 'UNIQUE (message_id)',
            'deliveries_pkey' => 'PRIMARY KEY (id)',
            'deliveries_status_check' => "CHECK ((status = ANY (ARRAY['pending'::text, 'delivered'::text, 'failed'::text])))",
            'deliveries_target_check' => "CHECK (((channel = 'email'::text) = (endpoint_id IS NULL)))",
            'deliveries_user_id_foreign' => 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
        ])
        ->and(p5Indexes('deliveries'))->toBe([
            'deliveries_alert_unique' => "CREATE UNIQUE INDEX deliveries_alert_unique ON deliveries USING btree (user_id, event_id) WHERE (kind = 'alert.triggered'::text)",
            'deliveries_digest_unique' => "CREATE UNIQUE INDEX deliveries_digest_unique ON deliveries USING btree (user_id, day) WHERE (kind = 'digest'::text)",
            'deliveries_due_index' => "CREATE INDEX deliveries_due_index ON deliveries USING btree (next_attempt_at) WHERE (status = 'pending'::text)",
            'deliveries_moved_unique' => "CREATE UNIQUE INDEX deliveries_moved_unique ON deliveries USING btree (event_id, endpoint_id) WHERE (kind = 'price.moved'::text)",
        ]);
});

it('creates dispatch_days exactly as D1', function (): void {
    expect(p5Columns('dispatch_days'))->toBe([
        'source' => ['type' => 'text', 'nullable' => false, 'default' => null, 'identity' => ''],
        'day' => ['type' => 'date', 'nullable' => false, 'default' => null, 'identity' => ''],
        'first_dispatched_at' => ['type' => 'timestamp with time zone', 'nullable' => false, 'default' => 'now()', 'identity' => ''],
        'event_id_ceiling' => ['type' => 'bigint', 'nullable' => true, 'default' => null, 'identity' => ''],
    ])
        ->and(p5Constraints('dispatch_days'))->toBe([
            'dispatch_days_pkey' => 'PRIMARY KEY (source, day)',
        ])
        ->and(p5Indexes('dispatch_days'))->toBe([]);
});

it('rejects rows the CHECKs and unique indexes forbid', function (string $sql, string $constraint): void {
    $user = AlertRows::user('owner@example.com');
    $discord = AlertRows::endpoint($user, 'discord');
    $standard = AlertRows::endpoint($user);
    $sql = strtr($sql, [':user' => (string) $user->id, ':standard' => (string) $standard, ':discord' => (string) $discord]);

    expect(p5Rejects($sql))->toContain($constraint);
})->with([
    'a bad endpoint kind' => ["INSERT INTO webhook_endpoints (user_id, kind, url, url_hint, secret) VALUES (:user, 'slack', 'x', 'x', 'x')", 'webhook_endpoints_kind_check'],
    'a standard endpoint without a secret' => ["INSERT INTO webhook_endpoints (user_id, kind, url, url_hint) VALUES (:user, 'standard', 'x', 'x')", 'webhook_endpoints_secret_check'],
    'a discord endpoint with a secret' => ["INSERT INTO webhook_endpoints (user_id, kind, url, url_hint, secret) VALUES (:user, 'discord', 'x', 'x', 'x')", 'webhook_endpoints_secret_check'],
    'a 101-character description' => ["INSERT INTO webhook_endpoints (user_id, kind, url, url_hint, secret, description) VALUES (:user, 'standard', 'x', 'x', 'x', repeat('d', 101))", 'webhook_endpoints_description_check'],
    'an empty description' => ["INSERT INTO webhook_endpoints (user_id, kind, url, url_hint, secret, description) VALUES (:user, 'standard', 'x', 'x', 'x', '')", 'webhook_endpoints_description_check'],
    'a second discord endpoint' => ["INSERT INTO webhook_endpoints (user_id, kind, url, url_hint) VALUES (:user, 'discord', 'x', 'x')", 'webhook_endpoints_one_discord_unique'],
    'a bad delivery kind' => ["INSERT INTO deliveries (message_id, user_id, kind, channel, endpoint_id, body, next_attempt_at) VALUES ('msg_a', :user, 'price.dropped', 'webhook', :standard, '{}', now())", 'deliveries_kind_check'],
    'a bad channel' => ["INSERT INTO deliveries (message_id, user_id, kind, channel, endpoint_id, body, next_attempt_at) VALUES ('msg_a', :user, 'price.moved', 'sms', :standard, '{}', now())", 'deliveries_channel_check'],
    'a bad status' => ["INSERT INTO deliveries (message_id, user_id, kind, channel, endpoint_id, body, status, next_attempt_at) VALUES ('msg_a', :user, 'price.moved', 'webhook', :standard, '{}', 'sent', now())", 'deliveries_status_check'],
    'a bad last_error' => ["INSERT INTO deliveries (message_id, user_id, kind, channel, endpoint_id, body, last_error, next_attempt_at) VALUES ('msg_a', :user, 'price.moved', 'webhook', :standard, '{}', 'boom', now())", 'deliveries_last_error_check'],
    'an email delivery with an endpoint' => ["INSERT INTO deliveries (message_id, user_id, kind, channel, endpoint_id, body, next_attempt_at) VALUES ('msg_a', :user, 'digest', 'email', :standard, '{}', now())", 'deliveries_target_check'],
    'a webhook delivery without an endpoint' => ["INSERT INTO deliveries (message_id, user_id, kind, channel, body, next_attempt_at) VALUES ('msg_a', :user, 'webhook.test', 'webhook', '{}', now())", 'deliveries_target_check'],
    'a duplicate message id' => ["INSERT INTO deliveries (message_id, user_id, kind, channel, endpoint_id, body, next_attempt_at) VALUES ('msg_a', :user, 'webhook.test', 'webhook', :standard, '{}', now()), ('msg_a', :user, 'webhook.test', 'webhook', :standard, '{}', now())", 'deliveries_message_id_unique'],
    'a second price.moved delivery of one event to one endpoint' => ["INSERT INTO deliveries (message_id, user_id, kind, channel, endpoint_id, event_id, body, next_attempt_at) VALUES ('msg_a', :user, 'price.moved', 'webhook', :standard, 7, '{}', now()), ('msg_b', :user, 'price.moved', 'webhook', :standard, 7, '{}', now())", 'deliveries_moved_unique'],
    'a second dispatch_days row of one day' => ["INSERT INTO dispatch_days (source, day) VALUES ('tcgplayer', '2026-10-12'), ('tcgplayer', '2026-10-12')", 'dispatch_days_pkey'],
]);

it('accepts the rows D1 allows, an email delivery without an endpoint among them', function (): void {
    $user = AlertRows::user('owner@example.com');
    $standard = AlertRows::endpoint($user);

    expect(p5Rejects("INSERT INTO deliveries (message_id, user_id, kind, channel, body, day, next_attempt_at) VALUES ('msg_e', {$user->id}, 'digest', 'email', '{}', '2026-10-12', now())"))->toBe('accepted')
        ->and(p5Rejects("INSERT INTO deliveries (message_id, user_id, kind, channel, endpoint_id, event_id, body, next_attempt_at) VALUES ('msg_w', {$user->id}, 'price.moved', 'webhook', {$standard}, 7, '{}', now())"))->toBe('accepted')
        ->and(p5Rejects("INSERT INTO webhook_endpoints (user_id, kind, url, url_hint, secret, description) VALUES ({$user->id}, 'standard', 'x', 'x', 'x', repeat('d', 100))"))->toBe('accepted');
});

it('deletes a user\'s endpoints and deliveries with the user, and an endpoint\'s deliveries with the endpoint', function (): void {
    $gone = AlertRows::user('gone@example.com');
    $kept = AlertRows::user('kept@example.com');
    $goneEndpoint = AlertRows::endpoint($gone);
    $keptEndpoint = AlertRows::endpoint($kept);
    $otherEndpoint = AlertRows::endpoint($kept);
    $insert = fn (string $id, int $user, ?int $endpoint, string $channel): bool => DB::table('deliveries')->insert([
        'message_id' => $id, 'user_id' => $user, 'kind' => 'webhook.test', 'channel' => $channel, 'endpoint_id' => $endpoint,
        'body' => '{}', 'next_attempt_at' => '2026-10-12 00:00:00+00',
    ]);
    $insert('msg_gone', $gone->id, $goneEndpoint, 'webhook');
    $insert('msg_gone_mail', $gone->id, null, 'email');
    $insert('msg_kept', $kept->id, $keptEndpoint, 'webhook');
    $insert('msg_other', $kept->id, $otherEndpoint, 'webhook');

    $gone->delete();
    DB::table('webhook_endpoints')->where('id', $otherEndpoint)->delete();

    expect(DB::table('webhook_endpoints')->orderBy('id')->pluck('id')->all())->toBe([$keptEndpoint])
        ->and(DB::table('deliveries')->orderBy('id')->pluck('message_id')->all())->toBe(['msg_kept']);
});

it('references no price_events, card_variants or price table: the only foreign keys go to users and webhook_endpoints', function (): void {
    $rows = DB::select(<<<'SQL'
        SELECT c.conrelid::regclass::text AS tbl, c.confrelid::regclass::text AS referenced
        FROM pg_constraint c
        WHERE c.contype = 'f' AND c.conrelid::regclass::text IN ('webhook_endpoints', 'deliveries', 'dispatch_days')
        ORDER BY 1, 2
        SQL);
    $mentions = DB::select(<<<'SQL'
        SELECT conname FROM pg_constraint
        WHERE conrelid::regclass::text IN ('webhook_endpoints', 'deliveries', 'dispatch_days')
          AND pg_get_constraintdef(oid) ~ '(price_|card_variants|ingest_runs|movement_volume|source_product_map)'
        SQL);

    expect(array_map(fn (object $r): array => [$r->tbl, $r->referenced], $rows))->toBe([
        ['deliveries', 'users'],
        ['deliveries', 'webhook_endpoints'],
        ['webhook_endpoints', 'users'],
    ])
        ->and($mentions)->toBe([]);
});

it('rolls the migration back and forth on the test database', function (): void {
    $migration = require database_path(P5_SCHEMA_MIGRATION);
    $before = [p5Constraints('webhook_endpoints'), p5Constraints('deliveries'), p5Constraints('dispatch_days'), p5Indexes('deliveries')];

    $migration->down();
    $down = DB::selectOne("SELECT to_regclass('webhook_endpoints')::text AS w, to_regclass('deliveries')::text AS d, to_regclass('dispatch_days')::text AS x");
    $migration->up();

    expect([$down->w, $down->d, $down->x])->toBe([null, null, null])
        ->and([p5Constraints('webhook_endpoints'), p5Constraints('deliveries'), p5Constraints('dispatch_days'), p5Indexes('deliveries')])->toBe($before);
});

it('stores the endpoint url and secret encrypted and never serialises them', function (): void {
    $user = AlertRows::user('owner@example.com');
    $id = AlertRows::endpoint($user);

    $raw = DB::table('webhook_endpoints')->where('id', $id)->first();
    $model = WebhookEndpoint::query()->findOrFail($id);
    $array = $model->toArray();
    $json = $model->toJson();

    expect($raw->url)->not->toBe(AlertRows::URL)
        ->and($raw->secret)->not->toBe(AlertRows::secret())
        ->and($raw->url)->not->toContain('hooks.example.com')
        ->and($raw->secret)->not->toContain('whsec_')
        ->and(Crypt::decryptString($raw->url))->toBe(AlertRows::URL)
        ->and($model->url)->toBe(AlertRows::URL)
        ->and($model->secret)->toBe(AlertRows::secret())
        ->and($array)->not->toHaveKey('url')
        ->and($array)->not->toHaveKey('secret')
        ->and($array)->toHaveKey('url_hint')
        ->and($json)->not->toContain('hooks.example.com/pokeprices')
        ->and($json)->not->toContain('whsec_')
        ->and($json)->not->toContain((string) $raw->url)
        ->and($json)->not->toContain((string) $raw->secret);
});

it('reads a delivery back through its model', function (): void {
    $user = AlertRows::user('owner@example.com');
    $endpoint = AlertRows::endpoint($user);
    DB::table('deliveries')->insert([
        'message_id' => 'msg_model', 'user_id' => $user->id, 'kind' => 'webhook.test', 'channel' => 'webhook',
        'endpoint_id' => $endpoint, 'body' => '{"a":1}', 'next_attempt_at' => '2026-10-12 00:00:00+00',
    ]);

    $delivery = Delivery::query()->where('message_id', 'msg_model')->firstOrFail();

    expect([$delivery->user_id, $delivery->endpoint_id, $delivery->status, $delivery->attempts, $delivery->claims, $delivery->body])
        ->toBe([$user->id, $endpoint, 'pending', 0, 0, '{"a":1}']);
});
