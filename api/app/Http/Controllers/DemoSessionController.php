<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Accounts\DemoAccount;
use App\Http\Problems\DemoUnavailable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;

#[Group('Account', 'The signed-in PokéPrices account (cookie session; sign in through the session endpoints first).')]
final class DemoSessionController
{
    /**
     * Sign in to the read-only demo account.
     *
     * Signs this browser session in as the shared demo account, which holds a seeded watchlist and portfolio and can
     * read them but change nothing (every write answers 403 demo-read-only). The session is regenerated. Send it as
     * a first-party request with the X-XSRF-TOKEN header from GET /sanctum/csrf-cookie. When the demo is switched off
     * or has not been seeded, the answer is 404 demo-unavailable.
     *
     * @operationId startDemoSession
     */
    public function __invoke(Request $request): Response
    {
        if (! DemoAccount::enabled()) {
            throw new DemoUnavailable;
        }
        $demo = DemoAccount::user();
        if ($demo === null) {
            throw new DemoUnavailable;
        }
        // Sanctum starts a session only for a first-party request; any other caller cannot hold one.
        if (! $request->hasSession()) {
            throw new TokenMismatchException('A first-party request with a session is required.');
        }

        Auth::guard('web')->login($demo);
        $request->session()->regenerate();

        return response()->noContent();
    }
}
