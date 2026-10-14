<?php

declare(strict_types=1);

use App\Domain\Accounts\AlertEligibility;
use App\Http\Middleware\RequireAlertEligibility;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\Support\Accounts;

// Plan P4-i1 T02 criterion 13 (D15): the verified-email seam P5 attaches to alert creation; no route uses it in P4.

it('allows a verified, non-demo person', function (): void {
    $user = Accounts::user();

    expect(AlertEligibility::allows($user))->toBeTrue()
        ->and(Gate::forUser($user)->allows('create-alerts'))->toBeTrue();
});

it('refuses an unverified person', function (): void {
    $user = Accounts::user(['email_verified_at' => null]);

    expect(AlertEligibility::allows($user))->toBeFalse()
        ->and(Gate::forUser($user)->allows('create-alerts'))->toBeFalse();
});

it('refuses the demo account even when verified', function (): void {
    $demo = Accounts::user(['email' => 'demo@example.com', 'is_demo' => true]);

    expect($demo->hasVerifiedEmail())->toBeTrue()
        ->and(AlertEligibility::allows($demo))->toBeFalse()
        ->and(Gate::forUser($demo)->allows('create-alerts'))->toBeFalse();
});

// P5-i1 T03 attaches the seam (same-commit supersession of 'is not attached to any route in P4'); T05 extends the list
// with alerts.put and notifications.put (same-commit supersession).
it('is attached to exactly the alert-creating routes', function (): void {
    $names = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array(RequireAlertEligibility::class, Route::gatherRouteMiddleware($route), true))
        ->map(fn ($route): ?string => $route->getName())
        ->sort()
        ->values()
        ->all();

    expect($names)->toBe(['alerts.put', 'notifications.put', 'webhooks.store', 'webhooks.test']);
});
