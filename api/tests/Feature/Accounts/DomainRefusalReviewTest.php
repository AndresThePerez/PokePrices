<?php

declare(strict_types=1);

use App\Domain\Accounts\AccountLimits;
use App\Domain\Accounts\AccountLock;
use App\Domain\Accounts\Portfolio;
use App\Domain\Accounts\Watchlist;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;

// Plan Q1-i4 T02 review: Watchlist::add and Portfolio::set now refuse by throwing from Domain. The order of the
// checks (key shape, then the variant, then the limit, then the insert) and "a refusal changes nothing and leaves no
// transaction open" must survive the move from return values to exceptions.

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
});

/** The errors of the ValidationException the call throws, or null when it returns. */
function q4t2Refusal(callable $call): ?array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return null;
}

it('checks the key shape before any SQL, then the variant, then the limit, for the watchlist and the portfolio', function (): void {
    config(['accounts.limits.watchlist' => 2, 'accounts.limits.portfolio' => 2]);
    $user = Accounts::user();
    AccountRows::watch($user, 'zz1-1:holo:-:-');
    AccountRows::watch($user, 'zz1-2:holo:-:-');
    AccountRows::hold($user, 'zz1-1:holo:-:-', 1);
    AccountRows::hold($user, 'zz1-2:holo:-:-', 1);
    AccountRows::variant('aa1-1:holo:-:-');
    $watchlist = app(Watchlist::class);
    $portfolio = app(Portfolio::class);
    $sql = [];
    DB::listen(function (QueryExecuted $query) use (&$sql): void {
        $sql[] = $query->sql;
    });
    $unknown = ['variant_key' => [AccountLimits::UNKNOWN_VARIANT]];

    $malformed = [q4t2Refusal(fn () => $watchlist->add($user->id, "base1-4:holo:-:-\n")), q4t2Refusal(fn () => $portfolio->set($user->id, 'NOT A KEY', 1))];
    $statementsForMalformed = $sql;
    $absent = [q4t2Refusal(fn () => $watchlist->add($user->id, 'zz9-9:holo:-:-')), q4t2Refusal(fn () => $portfolio->set($user->id, 'zz9-9:holo:-:-', 1))];
    $full = [q4t2Refusal(fn () => $watchlist->add($user->id, 'aa1-1:holo:-:-')), q4t2Refusal(fn () => $portfolio->set($user->id, 'aa1-1:holo:-:-', 1))];
    $already = [q4t2Refusal(fn () => $watchlist->add($user->id, 'zz1-2:holo:-:-')), q4t2Refusal(fn () => $portfolio->set($user->id, 'zz1-2:holo:-:-', 7))];

    expect($malformed)->toBe([$unknown, $unknown])
        ->and($statementsForMalformed)->toBe([])
        ->and($absent)->toBe([$unknown, $unknown])
        ->and($full)->toBe([
            ['variant_key' => ['Your watchlist holds at most 2 variants.']],
            ['variant_key' => ['Your portfolio holds at most 2 variants.']],
        ])
        ->and($already)->toBe([null, null])
        ->and(AccountRows::watched($user))->toBe(['zz1-1:holo:-:-', 'zz1-2:holo:-:-'])
        ->and(AccountRows::held($user))->toBe(['zz1-1:holo:-:-' => 1, 'zz1-2:holo:-:-' => 7]);
});

it('leaves no transaction open and no lock held after a refusal, so the same user writes again at once', function (): void {
    config(['accounts.limits.watchlist' => 1, 'accounts.limits.portfolio' => 1]);
    $user = Accounts::user();
    AccountRows::watch($user, 'zz1-1:holo:-:-');
    AccountRows::hold($user, 'zz1-1:holo:-:-', 1);
    AccountRows::variant('aa1-1:holo:-:-');
    $watchlist = app(Watchlist::class);
    $portfolio = app(Portfolio::class);
    // A second session that gives up on a lock after 250 ms: it can take the user's lock only if the refusals released it.
    config(['database.connections.pgsql_rival' => config('database.connections.pgsql')]);
    $rival = DB::connection('pgsql_rival');
    $rival->statement("SET lock_timeout = '250ms'");
    $level = DB::transactionLevel();

    for ($i = 0; $i < 3; $i++) {
        expect(q4t2Refusal(fn () => $watchlist->add($user->id, 'aa1-1:holo:-:-')))->not->toBeNull()
            ->and(q4t2Refusal(fn () => $portfolio->set($user->id, 'aa1-1:holo:-:-', 1)))->not->toBeNull();
    }
    $rivalGot = $rival->transaction(function () use ($rival, $user): string {
        AccountLock::forUser($rival, $user->id);

        return 'acquired';
    });
    DB::purge('pgsql_rival');
    $watchlist->remove($user->id, 'zz1-1:holo:-:-');
    $watchlist->add($user->id, 'aa1-1:holo:-:-');

    expect(DB::transactionLevel())->toBe($level)
        ->and($rivalGot)->toBe('acquired')
        ->and(AccountRows::watched($user))->toBe(['aa1-1:holo:-:-'])
        ->and(AccountRows::held($user))->toBe(['zz1-1:holo:-:-' => 1]);
});
