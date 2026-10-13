<?php

declare(strict_types=1);

use App\Http\Problems\ProblemDetails;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;

// Plan P4-i1 T08 criteria 1 and 2 (D6, D7, D8; reviews F-P4-03, F-P4-07, F-P4-08): GET /v1/me/export holds
// everything PokéPrices keeps about the person (account, watchlist, portfolio, session records) and no secret;
// POST /v1/me/deletion removes the account, its rows, its sessions and its reset tokens. Every /v1 response is also
// validated against openapi/api.json by Tests\TestCase (C3).

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
});

/**
 * Drops the guards and the in-memory session, so the next request knows the person only by its session cookie. The
 * container's default-guard singleton (auth.driver, which the database session handler asks for the row's user_id)
 * goes too: a guard kept from an earlier request would write the next request's session row without its user.
 */
function dataForgetMemory(): void
{
    Accounts::forgetGuards();
    app()->forgetInstance('auth.driver');
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
}

function dataSession(string $id, ?int $userId, int $lastActivity, ?string $ip = '192.0.2.50', ?string $agent = 'test-agent/1.0'): void
{
    DB::table('sessions')->insert([
        'id' => $id, 'user_id' => $userId, 'ip_address' => $ip, 'user_agent' => $agent,
        'payload' => base64_encode('a:1:{s:4:"seen";s:19:"fake-session-0000-x";}'), 'last_activity' => $lastActivity,
    ]);
}

function dataWatch(User $user, string $key, string $at): void
{
    DB::table('watchlist_items')->insert(['user_id' => $user->id, 'variant_key' => AccountRows::variant($key), 'created_at' => $at]);
}

function dataHold(User $user, string $key, int $quantity, string $added, string $updated): void
{
    DB::table('portfolio_holdings')->insert([
        'user_id' => $user->id, 'variant_key' => AccountRows::variant($key), 'quantity' => $quantity,
        'created_at' => $added, 'updated_at' => $updated,
    ]);
}

function dataResetToken(string $email): void
{
    DB::table('password_reset_tokens')->insert(['email' => $email, 'token' => Hash::make('fake-reset-token-0000'), 'created_at' => now()]);
}

/** A verified account with two-factor on, two watchlist items, two holdings and two session records. */
function dataPerson(): User
{
    $user = Accounts::user([
        'email' => 'collector@example.com',
        'email_verified_at' => '2026-10-01 09:45:00',
        'created_at' => '2026-10-01 09:30:00',
        'remember_token' => 'fake-remember-token-0000-0000-0000-0000-0000-0000-0000-0000-00',
        'two_factor_secret' => encrypt('FAKETOTPSECRET0000000000'),
        'two_factor_recovery_codes' => encrypt(json_encode(['fake-recovery-0001-aaaa', 'fake-recovery-0002-bbbb'])),
        'two_factor_confirmed_at' => '2026-10-02 08:00:00',
    ]);
    dataWatch($user, 'base1-4:holo:unlimited:-', '2026-10-03 10:00:00+00');
    dataWatch($user, 'ex5-17:reverse:-:-', '2026-10-02 11:15:30+00');
    dataHold($user, 'ex5-17:reverse:-:-', 3, '2026-10-04 12:00:00+00', '2026-10-05 13:30:00+00');
    dataHold($user, 'base1-4:holo:1st:-', 1, '2026-10-06 07:00:00+00', '2026-10-06 07:00:00+00');
    dataSession('session-person-older-000000000000001', $user->id, 1_760_000_000, '192.0.2.51', 'older-agent/1.0');
    dataSession('session-person-newer-000000000000002', $user->id, 1_760_086_400, '198.51.100.52', null);

    return $user;
}

/** Someone else, with rows and a session record of their own. */
function dataOther(): User
{
    $other = Accounts::user(['email' => 'other@example.com']);
    dataWatch($other, 'zz9-9:normal:-:-', '2026-10-07 10:00:00+00');
    dataHold($other, 'zz9-9:normal:-:-', 7, '2026-10-07 10:00:00+00', '2026-10-07 10:00:00+00');
    dataSession('session-other-000000000000000000003', $other->id, 1_760_100_000, '203.0.113.53', 'other-agent/9.9');
    dataResetToken('other@example.com');

    return $other;
}

/** True when $needle appears in the raw JSON body as is or JSON-escaped (a hash's slashes arrive as \/). */
function dataBodyHolds(string $raw, string $needle): bool
{
    return str_contains($raw, $needle) || str_contains($raw, substr((string) json_encode($needle), 1, -1));
}

/** @return array<string, int> row counts of every table that holds a person's data */
function dataCounts(): array
{
    $counts = [];
    foreach (['users', 'watchlist_items', 'portfolio_holdings', 'sessions', 'password_reset_tokens'] as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    return $counts;
}

/** @return array<string, mixed> */
function dataSpec(): array
{
    $path = tempnam(sys_get_temp_dir(), 'pp-account-data-spec-');
    Artisan::call('scramble:export', ['--path' => $path]);
    $spec = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    @unlink($path);

    return $spec;
}

// ---- Export (criterion 1) ----------------------------------------------------------------------

it('exports exactly D6\'s shape: the account, both lists by variant key and the session records newest first', function (): void {
    $user = dataPerson();
    dataOther();

    $response = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me/export');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="pokeprices-export.json"')
        ->and($response->json())->toBe(['data' => [
            'format' => 'pokeprices-account-export',
            'version' => 1,
            'account' => [
                'email' => 'collector@example.com',
                'email_verified_at' => '2026-10-01T09:45:00Z',
                'two_factor' => true,
                'created_at' => '2026-10-01T09:30:00Z',
            ],
            'watchlist' => [
                ['variant_key' => 'base1-4:holo:unlimited:-', 'added_at' => '2026-10-03T10:00:00Z'],
                ['variant_key' => 'ex5-17:reverse:-:-', 'added_at' => '2026-10-02T11:15:30Z'],
            ],
            'portfolio' => [
                ['variant_key' => 'base1-4:holo:1st:-', 'quantity' => 1, 'added_at' => '2026-10-06T07:00:00Z', 'updated_at' => '2026-10-06T07:00:00Z'],
                ['variant_key' => 'ex5-17:reverse:-:-', 'quantity' => 3, 'added_at' => '2026-10-04T12:00:00Z', 'updated_at' => '2026-10-05T13:30:00Z'],
            ],
            'sessions' => [
                ['last_active_at' => '2025-10-10T08:53:20Z', 'ip_address' => '198.51.100.52', 'user_agent' => null],
                ['last_active_at' => '2025-10-09T08:53:20Z', 'ip_address' => '192.0.2.51', 'user_agent' => 'older-agent/1.0'],
            ],
        ]]);
});

it('keeps the export out of every cache: Cache-Control no-store and private, no ETag', function (): void {
    $user = dataPerson();

    $response = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me/export');

    $cacheControl = (string) $response->headers->get('Cache-Control');
    expect($response->getStatusCode())->toBe(200)
        ->and($cacheControl)->toContain('no-store')
        ->and($cacheControl)->toContain('private')
        ->and($response->headers->has('ETag'))->toBeFalse();
});

it('holds no secret: not the password hash, remember token, two-factor secret, recovery codes, session ids or payloads', function (): void {
    $user = dataPerson();
    dataOther();
    $stored = (array) DB::table('users')->where('id', $user->id)->first(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes']);
    $secrets = array_values(array_filter($stored, 'is_string'));
    $secrets[] = (string) decrypt((string) $stored['two_factor_secret']);
    foreach (json_decode((string) decrypt((string) $stored['two_factor_recovery_codes']), true) as $code) {
        $secrets[] = (string) $code;
    }
    foreach (DB::table('sessions')->get(['id', 'payload']) as $session) {
        $secrets[] = (string) $session->id;
        $secrets[] = (string) $session->payload;
    }
    $secrets[] = 'fake-session-0000-x';

    $response = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me/export');
    $raw = (string) $response->getContent();

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json('data.account.email'))->toBe('collector@example.com')
        ->and(count($secrets))->toBe(14);
    foreach ($secrets as $index => $secret) {
        // The message names the position only, never the value.
        expect(dataBodyHolds($raw, $secret))->toBeFalse("stored value #{$index} appears in the export");
    }
});

it('never exports another user\'s rows or sessions, and a user without rows gets empty lists', function (): void {
    dataPerson();
    $other = dataOther();
    $empty = Accounts::user(['email' => 'empty@example.com', 'email_verified_at' => null]);

    $theirs = AccountRows::signIn($this, $other)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me/export');
    $none = AccountRows::signIn($this, $empty)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me/export');

    expect($theirs->json('data.account.email'))->toBe('other@example.com')
        ->and($theirs->json('data.account.two_factor'))->toBeFalse()
        ->and($theirs->json('data.watchlist'))->toBe([['variant_key' => 'zz9-9:normal:-:-', 'added_at' => '2026-10-07T10:00:00Z']])
        ->and(array_column($theirs->json('data.portfolio'), 'quantity'))->toBe([7])
        ->and($theirs->json('data.sessions'))->toBe([['last_active_at' => '2025-10-10T12:40:00Z', 'ip_address' => '203.0.113.53', 'user_agent' => 'other-agent/9.9']])
        ->and((string) $theirs->getContent())->not->toContain('collector@example.com')
        ->and((string) $theirs->getContent())->not->toContain('192.0.2.51');
    expect($none->json('data.account.email_verified_at'))->toBeNull()
        ->and($none->json('data.watchlist'))->toBe([])
        ->and($none->json('data.portfolio'))->toBe([])
        ->and($none->json('data.sessions'))->toBe([]);
});

it('lets the read-only demo export (it is a read)', function (): void {
    $demo = Accounts::user(['email' => 'demo@example.com', 'is_demo' => true]);

    $response = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me/export');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json('data.account.email'))->toBe('demo@example.com');
});

it('answers a signed-out export with the 401 unauthenticated problem', function (): void {
    $response = $this->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me/export');

    expect($response->getStatusCode())->toBe(401)
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'unauthenticated');
});

// ---- Deletion (criterion 2) --------------------------------------------------------------------

it('deletes the account with the right password: the user, both lists, every session and the reset token; others untouched', function (): void {
    // The database driver, as on the dev stack, so the session row of this very sign-in is real and the next
    // request knows the person only by the cookie the sign-in set.
    // The garbage-collection lottery always runs, so the outcome never depends on chance.
    config(['session.driver' => 'database', 'session.lottery' => [100, 100]]);
    dataForgetMemory();
    $user = dataPerson();
    $other = dataOther();
    dataResetToken('collector@example.com');
    // Recent activity: the database handler's garbage-collection lottery would otherwise drop the old fixture rows.
    DB::table('sessions')->update(['last_activity' => now()->getTimestamp() - 60]);
    $cookie = (string) config('session.cookie');
    $login = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/login', ['email' => 'collector@example.com', 'password' => Accounts::PASSWORD]);
    // dataPerson() turned two-factor on, so the sign-in waits for the challenge; it is passed with the session.
    expect($login->json('two_factor'))->toBeTrue();
    $sessionId = (string) Accounts::sessionId($login);
    dataForgetMemory();
    $challenge = $this->withHeaders(Accounts::FIRST_PARTY)->withCredentials()->withCookie($cookie, $sessionId)
        ->postJson('/two-factor-challenge', ['recovery_code' => 'fake-recovery-0001-aaaa']);
    $challenge->assertNoContent();
    $signedIn = (string) Accounts::sessionId($challenge);
    expect($signedIn)->not->toBe('')
        ->and(DB::table('sessions')->where('id', $signedIn)->value('user_id'))->toBe($user->id)
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(3);
    $otherBefore = [
        'watchlist' => AccountRows::watched($other), 'portfolio' => AccountRows::held($other),
        'sessions' => DB::table('sessions')->where('user_id', $other->id)->pluck('id')->all(),
        'tokens' => DB::table('password_reset_tokens')->where('email', 'other@example.com')->count(),
    ];

    dataForgetMemory();
    $deleted = $this->withHeaders(Accounts::FIRST_PARTY)->withCredentials()->withCookie($cookie, $signedIn)
        ->postJson('/v1/me/deletion', ['password' => Accounts::PASSWORD]);

    expect($deleted->getStatusCode())->toBe(204)
        ->and($deleted->getContent())->toBe('')
        ->and(DB::table('users')->where('id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('watchlist_items')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('portfolio_holdings')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('sessions')->whereIn('id', ['session-person-older-000000000000001', 'session-person-newer-000000000000002', $signedIn])->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->where('email', 'collector@example.com')->count())->toBe(0)
        ->and([
            'watchlist' => AccountRows::watched($other), 'portfolio' => AccountRows::held($other),
            'sessions' => DB::table('sessions')->where('user_id', $other->id)->pluck('id')->all(),
            'tokens' => DB::table('password_reset_tokens')->where('email', 'other@example.com')->count(),
        ])->toBe($otherBefore)
        ->and(DB::table('users')->where('id', $other->id)->exists())->toBeTrue();

    dataForgetMemory();
    $after = $this->withHeaders(Accounts::FIRST_PARTY)->withCredentials()->withCookie($cookie, $signedIn)->getJson('/v1/me');
    expect($after->getStatusCode())->toBe(401)
        ->and($after->json('type'))->toBe(ProblemDetails::BASE.'unauthenticated');
});

it('lets the same email register again (with the invite) once the account is deleted', function (): void {
    $user = Accounts::user(['email' => 'collector@example.com']);
    AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->postJson('/v1/me/deletion', ['password' => Accounts::PASSWORD])->assertNoContent();

    dataForgetMemory();
    $again = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/register', [
        'invite_code' => Accounts::INVITE, 'email' => 'collector@example.com',
        'password' => Accounts::PASSWORD, 'password_confirmation' => Accounts::PASSWORD,
    ]);

    expect($again->getStatusCode())->toBe(201)
        ->and(User::query()->where('email', 'collector@example.com')->count())->toBe(1)
        ->and(User::query()->where('email', 'collector@example.com')->value('id'))->not->toBe($user->id);
});

it('answers a wrong password with 422 errors.password and deletes nothing', function (): void {
    $user = dataPerson();
    dataResetToken('collector@example.com');
    $before = dataCounts();

    $response = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->postJson('/v1/me/deletion', ['password' => 'not-the-password-0000']);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'validation-failed')
        ->and(array_keys($response->json('errors')))->toBe(['password'])
        ->and(dataCounts())->toBe($before);
});

it('answers a missing or non-string password with 422 errors.password, never 500 (F5)', function (mixed $password): void {
    $user = Accounts::user();
    $body = $password === 'absent' ? [] : ['password' => $password];

    $response = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->postJson('/v1/me/deletion', $body);

    expect($response->getStatusCode())->toBe(422)
        ->and(array_keys($response->json('errors')))->toBe(['password'])
        ->and(DB::table('users')->where('id', $user->id)->exists())->toBeTrue();
})->with([
    'absent' => ['absent'],
    'null' => [null],
    'empty' => [''],
    'array' => [[Accounts::PASSWORD]],
    'integer' => [123456789012],
    'boolean' => [true],
]);

it('answers the sixth wrong-password deletion of one user within a minute with 429 and deletes nothing (password-confirm)', function (): void {
    $user = dataPerson();
    $before = dataCounts();
    for ($i = 0; $i < 5; $i++) {
        AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->postJson('/v1/me/deletion', ['password' => 'not-the-password-0000'])->assertStatus(422);
    }

    $sixth = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->postJson('/v1/me/deletion', ['password' => 'not-the-password-0000']);
    $right = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->postJson('/v1/me/deletion', ['password' => Accounts::PASSWORD]);
    $someoneElse = Accounts::user(['email' => 'other@example.com']);
    $theirs = AccountRows::signIn($this, $someoneElse)->withHeaders(Accounts::FIRST_PARTY)->postJson('/v1/me/deletion', ['password' => 'not-the-password-0000']);

    expect($sixth->getStatusCode())->toBe(429)
        ->and($sixth->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and($sixth->headers->has('Retry-After'))->toBeTrue()
        ->and($right->getStatusCode())->toBe(429)
        ->and(DB::table('users')->where('id', $user->id)->exists())->toBeTrue()
        ->and(dataCounts())->toBe(['users' => $before['users'] + 1] + $before)
        ->and($theirs->getStatusCode())->toBe(422);
});

it('refuses the demo with 403 demo-read-only and deletes nothing', function (): void {
    $demo = Accounts::user(['email' => 'demo@example.com', 'is_demo' => true]);
    AccountRows::watch($demo, 'base1-4:holo:unlimited:-');
    $before = dataCounts();

    $response = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->postJson('/v1/me/deletion', ['password' => Accounts::PASSWORD]);

    expect($response->getStatusCode())->toBe(403)
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'demo-read-only')
        ->and(dataCounts())->toBe($before);
});

it('answers a signed-out deletion with the 401 unauthenticated problem', function (): void {
    Accounts::user();
    $before = dataCounts();

    $response = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/v1/me/deletion', ['password' => Accounts::PASSWORD]);

    expect($response->getStatusCode())->toBe(401)
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'unauthenticated')
        ->and(dataCounts())->toBe($before);
});

// ---- Contract (criterion 2: D4's guard, one documented success code each) ----------------------

it('documents POST /v1/me/deletion with a password request body, never a password parameter, and its answers', function (): void {
    $spec = dataSpec();
    $deletion = $spec['paths']['/me/deletion']['post'] ?? null;

    expect($deletion)->not->toBeNull()
        ->and($deletion['parameters'] ?? [])->toBe([])
        ->and($deletion['requestBody']['content']['application/json']['schema']['properties'])->toHaveKey('password')
        ->and($deletion['requestBody']['content']['application/json']['schema']['required'])->toBe(['password'])
        ->and(array_map('strval', array_keys($deletion['responses'])))->toBe(['204', '401', '403', '419', '422', '429']);
});

it('documents GET /v1/me/export with 200, 401 and 429 and a closed body of D6\'s keys', function (): void {
    $spec = dataSpec();
    $export = $spec['paths']['/me/export']['get'] ?? null;

    expect($export)->not->toBeNull()
        ->and(array_map('strval', array_keys($export['responses'])))->toBe(['200', '401', '429']);
    $schema = $export['responses']['200']['content']['application/json']['schema'];
    $ref = $schema['properties']['data']['$ref'] ?? null;
    $data = is_string($ref) ? $spec['components']['schemas'][substr($ref, strlen('#/components/schemas/'))] : $schema['properties']['data'];
    expect($schema['additionalProperties'])->toBeFalse()
        ->and(array_keys($data['properties']))->toBe(['format', 'version', 'account', 'watchlist', 'portfolio', 'sessions'])
        ->and($data['additionalProperties'])->toBeFalse()
        ->and(array_keys($data['properties']['account']['properties']))->toBe(['email', 'email_verified_at', 'two_factor', 'created_at'])
        ->and(array_keys($data['properties']['sessions']['items']['properties']))->toBe(['last_active_at', 'ip_address', 'user_agent']);
});
