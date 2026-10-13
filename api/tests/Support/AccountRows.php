<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Rows and a faked PokéSearch for the watchlist, portfolio and demo tests (plan P4-i1 T05, T06). Variants and their
 * latest closes are inserted straight into card_variants and price_latest (test data only: the code under test
 * never writes them); PokéSearch is faked by path, and catalog keys live under CatalogCache::isolate()'s prefix.
 */
final class AccountRows
{
    public const string BASE = 'http://192.0.2.10:8081';

    public const string SEED_REF = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    /** Card ids the fake catalog does not have; the batch answers them in missing, the card read with 404. */
    public static array $missing = [];

    /** When true, every PokéSearch request after the seed ref fails with 503. */
    public static bool $down = false;

    /**
     * Signs $user in for the next request on the web guard, which auth:sanctum consults first, after dropping every
     * resolved guard and the in-memory session: Sanctum's RequestGuard keeps the user of an earlier request, and the
     * test session store keeps an earlier user's password hash, which Sanctum's AuthenticateSession would compare
     * with the next user's and sign them out. Switching users needs both gone.
     *
     * @template T of object
     *
     * @param  T  $test  the running TestCase
     * @return T
     */
    public static function signIn(object $test, User $user): object
    {
        Accounts::forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        config(['auth.defaults.guard' => 'web']);
        $test->actingAs($user, 'web');

        return $test;
    }

    /** Inserts the card_variants row of "<card>:<finish>:<edition>:<treatment>" (when new) and returns the key. */
    public static function variant(string $key): string
    {
        [$card, $finish, $edition, $treatment] = explode(':', $key);
        DB::table('card_variants')->insertOrIgnore([
            'variant_key' => $key, 'card_id' => $card, 'finish' => $finish, 'edition' => $edition,
            'treatment' => $treatment, 'catalog_status' => 'unverified',
        ]);

        return $key;
    }

    /** Inserts the variant (when new) and its price_latest row with a close on $day (as_of 20:05:45 UTC that day). */
    public static function price(string $key, string $close, string $day = '2026-10-11', ?string $previous = null, ?string $previousDay = null, ?string $change = null, ?string $changePct = null): void
    {
        self::variant($key);
        DB::table('price_latest')->insert([
            'variant_key' => $key, 'source' => 'tcgplayer', 'series_epoch' => 1, 'close' => $close, 'day' => $day,
            'as_of' => $day.' 20:05:45+00', 'previous_close' => $previous, 'previous_day' => $previousDay,
            'change' => $change, 'change_pct' => $changePct,
        ]);
    }

    public static function watch(User $user, string $key): void
    {
        DB::table('watchlist_items')->insert(['user_id' => $user->id, 'variant_key' => self::variant($key)]);
    }

    public static function hold(User $user, string $key, int $quantity): void
    {
        DB::table('portfolio_holdings')->insert(['user_id' => $user->id, 'variant_key' => self::variant($key), 'quantity' => $quantity]);
    }

    /** @return list<string> the user's watchlist keys, sorted */
    public static function watched(User $user): array
    {
        /** @var list<string> */
        return DB::table('watchlist_items')->where('user_id', $user->id)->orderBy('variant_key')->pluck('variant_key')->all();
    }

    /** @return array<string, int> the user's holdings, key => quantity, sorted */
    public static function held(User $user): array
    {
        $rows = DB::table('portfolio_holdings')->where('user_id', $user->id)->orderBy('variant_key')->get(['variant_key', 'quantity']);
        $held = [];
        foreach ($rows as $row) {
            $held[(string) $row->variant_key] = (int) $row->quantity;
        }

        return $held;
    }

    /** The catalog card of $id as PokéSearch's batch and card reads carry it. */
    public static function card(string $id): array
    {
        return [
            'id' => $id, 'name' => "Name of {$id}", 'supertype' => 'Pokémon', 'subtypes' => ['Basic'], 'hp' => 60, 'types' => ['Fire'],
            'rarity' => 'Rare Holo', 'number' => '4', 'artist' => 'Mitsuhiro Arita', 'set_id' => explode('-', $id)[0],
            'set_name' => "Set of {$id}", 'set_series' => 'Base', 'set_total' => 102, 'release_date' => '1999-01-09',
            'image_small' => 'https://images.example.com/card.png', 'image_large' => 'https://images.example.com/card_hires.png',
        ];
    }

    /** Points the api at the fake PokéSearch, with a fresh catalog prefix; returns the prefix for CatalogCache::flush(). */
    public static function fakePokeSearch(): string
    {
        self::$missing = [];
        self::$down = false;
        config(['services.pokesearch.url' => self::BASE, 'services.pokesearch.api_key' => null]);
        $prefix = CatalogCache::isolate();
        Http::preventStrayRequests();
        Http::fake(fn (ClientRequest $request): PromiseInterface => self::answer($request));

        return $prefix;
    }

    /** @return list<list<string>> the ids of every recorded batch name read, in order */
    public static function batches(): array
    {
        return Http::recorded(fn (ClientRequest $r): bool => parse_url($r->url(), PHP_URL_PATH) === '/api/v1/cards')
            ->map(function (array $pair): array {
                parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

                return explode(',', (string) $query['ids']);
            })->values()->all();
    }

    private static function answer(ClientRequest $request): PromiseInterface
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $seed = ['ref' => self::SEED_REF, 'seeded_at' => '2026-10-11T20:05:45Z'];
        if ($path === '/api/meta') {
            return Http::response(['version' => 'test', 'commit' => '0000000', 'built' => '', 'docs' => 3, 'seed' => $seed, 'request_id' => 'upstream-meta']);
        }
        if (self::$down) {
            return Http::response(['error' => 'unavailable'], 503);
        }
        if ($path === '/api/v1/cards') {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $data = [];
            $missing = [];
            foreach (explode(',', (string) $query['ids']) as $id) {
                in_array($id, self::$missing, true) ? $missing[] = $id : $data[] = self::card($id);
            }

            return Http::response(['data' => $data, 'missing' => $missing, 'seed' => $seed]);
        }
        if (str_starts_with($path, '/api/v1/cards/')) {
            $id = rawurldecode(substr($path, strlen('/api/v1/cards/')));

            return in_array($id, self::$missing, true)
                ? Http::response(['error' => 'not found'], 404)
                : Http::response(['data' => self::card($id), 'seed' => $seed]);
        }

        throw new RuntimeException("unrouted PokéSearch request {$path}");
    }
}
