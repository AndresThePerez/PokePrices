<?php

declare(strict_types=1);

use App\Domain\Accounts\AlertEligibility;
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

it('is not attached to any route in P4', function (): void {
    $uses = collect(Route::getRoutes()->getRoutes())
        ->flatMap(fn ($route): array => Route::gatherRouteMiddleware($route))
        ->filter(fn (mixed $m): bool => is_string($m) && str_contains($m, 'create-alerts'))
        ->all();

    expect($uses)->toBe([]);
});
