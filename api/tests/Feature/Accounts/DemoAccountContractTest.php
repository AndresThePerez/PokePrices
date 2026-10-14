<?php

declare(strict_types=1);

use App\Http\Middleware\RequireAlertEligibility;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

// Plan P4-i1 T05 criterion 2 and T06 criterion 5 (D4, D6): the /v1 contract documents the watchlist, portfolio and
// demo-session operations with exactly their statuses, 403 demo-read-only on every non-GET account operation and
// closed bodies.

/** @return array<string, mixed> */
function demoContractExport(): array
{
    $path = tempnam(sys_get_temp_dir(), 'pp-demo-spec-');
    Artisan::call('scramble:export', ['--path' => $path]);
    $spec = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    @unlink($path);

    return $spec;
}

/** Follows a local $ref into components. */
function demoContractResolve(array $spec, array $schema): array
{
    $ref = $schema['$ref'] ?? null;

    return is_string($ref) ? $spec['components']['schemas'][substr($ref, strlen('#/components/schemas/'))] : $schema;
}

it('documents each watchlist, portfolio and demo operation with exactly its statuses', function (string $path, string $method, array $codes): void {
    $operation = demoContractExport()['paths'][$path][$method] ?? null;

    expect($operation)->not->toBeNull()
        ->and(array_map('strval', array_keys($operation['responses'])))->toBe($codes);
})->with([
    ['/watchlist', 'get', ['200', '401', '429']],
    ['/watchlist/{variantKey}', 'put', ['200', '401', '403', '419', '422', '429']],
    ['/watchlist/{variantKey}', 'delete', ['204', '401', '403', '419', '429']],
    ['/portfolio', 'get', ['200', '401', '429']],
    ['/portfolio/{variantKey}', 'put', ['200', '401', '403', '419', '422', '429']],
    ['/portfolio/{variantKey}', 'delete', ['204', '401', '403', '419', '429']],
    ['/demo/session', 'post', ['204', '404', '419', '429']],
]);

it('documents 403 demo-read-only as a problem on every non-GET account operation, and never on a GET', function (): void {
    $spec = demoContractExport();
    $writes = 0;

    foreach ($spec['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            $accountWrite = $method !== 'get' && $path !== '/demo/session' && array_key_exists('401', $operation['responses']);
            if (! $accountWrite) {
                expect($operation['responses'])->not->toHaveKey('403', "{$method} {$path}");

                continue;
            }
            $writes++;
            // P5-i1 T03 (R235, same-commit supersession): an operation gated by RequireAlertEligibility names both 403s.
            expect($operation['responses']['403']['description'] ?? null)->toBe('The demo account is read-only (problem type https://pokeprices.andrestheperez.com/problems/demo-read-only)'.(collect(Route::getRoutes()->getRoutes())->contains(fn ($r): bool => $r->uri() === 'v1'.$path && in_array(strtoupper($method), $r->methods(), true) && in_array(RequireAlertEligibility::class, Route::gatherRouteMiddleware($r), true)) ? ', or the account\'s email address is not verified yet (problem type https://pokeprices.andrestheperez.com/problems/email-not-verified).' : '.'), "{$method} {$path}")
                ->and($operation['responses']['403']['content'])->toBe(['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]]);
        }
    }
    expect($writes)->toBeGreaterThanOrEqual(4);
});

it('documents the closed list bodies: watchlist items, portfolio holdings and the portfolio meta', function (): void {
    $spec = demoContractExport();
    $watchlist = $spec['paths']['/watchlist']['get']['responses']['200']['content']['application/json']['schema'];
    $portfolio = $spec['paths']['/portfolio']['get']['responses']['200']['content']['application/json']['schema'];
    $item = demoContractResolve($spec, $watchlist['properties']['data']['items']);
    $holding = demoContractResolve($spec, $portfolio['properties']['data']['items']);
    $meta = $portfolio['properties']['meta'];

    $itemKeys = ['variant_key', 'card_id', 'finish', 'edition', 'treatment', 'card', 'price'];
    // P5-i1 T05 (same-commit supersession): the watchlist item gains alert_pct as its last key; the holding does not.
    expect(array_keys($item['properties']))->toBe([...$itemKeys, 'alert_pct'])
        ->and($item['additionalProperties'])->toBeFalse()
        ->and(array_keys($holding['properties']))->toBe([...$itemKeys, 'quantity', 'value'])
        ->and($holding['additionalProperties'])->toBeFalse()
        ->and(array_keys($watchlist['properties']['meta']['properties']))->toBe(['count', 'limit', 'degraded'])
        ->and(array_keys($meta['properties']))->toBe(['count', 'limit', 'total_value', 'priced', 'unpriced', 'first_day', 'last_day', 'source', 'feed', 'basis', 'currency', 'degraded'])
        ->and($meta['additionalProperties'])->toBeFalse();
});

it('takes the quantity in a closed request body, never in the query', function (): void {
    $operation = demoContractExport()['paths']['/portfolio/{variantKey}']['put'];
    $body = $operation['requestBody']['content']['application/json']['schema'];

    expect($body['properties']['quantity']['type'])->toBe('integer')
        ->and($body['required'])->toBe(['quantity'])
        ->and($body['additionalProperties'])->toBeFalse()
        ->and(array_filter($operation['parameters'] ?? [], fn (array $p): bool => ($p['in'] ?? '') === 'query'))->toBe([]);
});
