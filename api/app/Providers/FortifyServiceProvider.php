<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Domain\Accounts\AccountLinks;
use App\Domain\Accounts\AccountRateLimits;
use App\Domain\Accounts\AlertEligibility;
use App\Domain\Accounts\ResetFailedAnswer;
use App\Domain\Accounts\ResetLinkAnswer;
use App\Http\Middleware\NormalizeAccountEmail;
use App\Http\Middleware\NoStore;
use App\Http\Middleware\ThrottleBeforeSession;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\Sanctum;
use RuntimeException;

/**
 * Fortify and Sanctum for PokéPrices: the actions, the account limiters, the two
 * mailed links, the enumeration-safe reset-link answer, no API tokens, the create-alerts Gate, and the limiters and
 * email normalisation on the package routes, which exist by now (package providers boot before this one).
 */
final class FortifyServiceProvider extends ServiceProvider
{
    /** Route middleware added to the named package routes, after the packages register them (D1, D3, D7). */
    private const array ROUTE_MIDDLEWARE = [
        'register.store' => [NormalizeAccountEmail::class, 'throttle:register'],
        'login.store' => [NormalizeAccountEmail::class],
        'password.email' => [ThrottleBeforeSession::class.':account-guest', NormalizeAccountEmail::class],
        'password.update' => [ThrottleBeforeSession::class.':account-guest', NormalizeAccountEmail::class],
        'verification.send' => [NormalizeAccountEmail::class],
        'sanctum.csrf-cookie' => [ThrottleBeforeSession::class.':account-guest'],
        'password.confirm.store' => ['throttle:password-confirm'],
        'two-factor.confirm' => ['throttle:password-confirm'],
    ];

    public function register(): void
    {
        $this->app->singleton(SuccessfulPasswordResetLinkRequestResponse::class, ResetLinkAnswer::class);
        $this->app->singleton(FailedPasswordResetLinkRequestResponse::class, ResetLinkAnswer::class);
        $this->app->bind(FailedPasswordResetResponse::class, ResetFailedAnswer::class);
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        AccountRateLimits::register();

        VerifyEmail::createUrlUsing(fn (MustVerifyEmail $notifiable): string => AccountLinks::verification($notifiable));
        ResetPassword::createUrlUsing(fn (CanResetPassword $user, string $token): string => AccountLinks::reset($user, $token));

        // Cookie sessions only: an Authorization header is never read, so no personal_access_tokens query runs.
        Sanctum::getAccessTokenFromRequestUsing(fn (): ?string => null);

        Gate::define(AlertEligibility::GATE, fn (User $user): bool => AlertEligibility::allows($user));

        $this->attachRouteMiddleware();

        // account-guest must count a request before Sanctum's stateful pipeline starts a session,
        // and NoStore sorts ahead of it so a throttled (429) account or demo-session answer is no-store too.
        // Each prepend goes to the front, so NoStore is prepended last.
        $kernel = $this->app->make(KernelContract::class);
        if ($kernel instanceof Kernel) {
            $kernel->prependToMiddlewarePriority(ThrottleBeforeSession::class);
            $kernel->prependToMiddlewarePriority(NoStore::class);
        }
    }

    private function attachRouteMiddleware(): void
    {
        $routes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = $route->getName();
            if (is_string($name) && isset(self::ROUTE_MIDDLEWARE[$name])) {
                $routes[$name] = $route;
            }
        }
        foreach (self::ROUTE_MIDDLEWARE as $name => $middleware) {
            $route = $routes[$name] ?? null;
            if (! $route instanceof RoutingRoute) {
                throw new RuntimeException("PokéPrices expects the package route {$name}; check config/fortify.php features.");
            }
            $route->middleware($middleware);
        }
    }
}
