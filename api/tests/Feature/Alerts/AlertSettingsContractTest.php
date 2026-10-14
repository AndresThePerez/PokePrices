<?php

declare(strict_types=1);

// Plan P5-i1 T05 criterion 7 (D9): the alert and notification operations in the committed contract
// (openapi/api.json) with exactly their statuses and closed bodies, the 403 of the gated writes naming both problem
// types, the watchlist item widened by alert_pct (the holding unchanged) and the export's version 2 keys.

/** @return array<string, mixed> */
function t5Spec(): array
{
    return json_decode((string) file_get_contents(base_path('../openapi/api.json')), true, 512, JSON_THROW_ON_ERROR);
}

/** Follows local $refs until a schema that is not a reference. */
function t5Deref(array $spec, array $schema): array
{
    while (isset($schema['$ref']) && is_string($schema['$ref'])) {
        $schema = $spec['components']['schemas'][substr($schema['$ref'], strlen('#/components/schemas/'))];
    }

    return $schema;
}

/** The 200 (or $code) application/json body schema of an operation, dereferenced. */
function t5Body(array $spec, string $path, string $method, string $code = '200'): array
{
    return t5Deref($spec, $spec['paths'][$path][$method]['responses'][$code]['content']['application/json']['schema']);
}

it('documents the alert and notification operations with exactly their statuses and operation ids', function (): void {
    $paths = t5Spec()['paths'];
    $codes = fn (string $path, string $method): array => array_map('strval', array_keys($paths[$path][$method]['responses']));

    expect(array_keys($paths['/alerts/{variantKey}']))->toBe(['put', 'delete'])
        ->and(array_keys($paths['/me/notifications']))->toBe(['get', 'put'])
        ->and($codes('/alerts/{variantKey}', 'put'))->toBe(['200', '401', '403', '419', '422', '429'])
        ->and($codes('/alerts/{variantKey}', 'delete'))->toBe(['204', '401', '403', '419', '429'])
        ->and($codes('/me/notifications', 'get'))->toBe(['200', '401', '429'])
        ->and($codes('/me/notifications', 'put'))->toBe(['200', '401', '403', '419', '422', '429'])
        ->and([
            $paths['/alerts/{variantKey}']['put']['operationId'], $paths['/alerts/{variantKey}']['delete']['operationId'],
            $paths['/me/notifications']['get']['operationId'], $paths['/me/notifications']['put']['operationId'],
        ])->toBe(['putAlert', 'deleteAlert', 'getNotifications', 'putNotifications']);
});

it('names both 403 problem types on the gated writes and only demo-read-only on DELETE /alerts', function (): void {
    $paths = t5Spec()['paths'];

    foreach ([['/alerts/{variantKey}', 'put'], ['/me/notifications', 'put']] as [$path, $method]) {
        expect($paths[$path][$method]['responses']['403']['description'])->toContain('demo-read-only')->toContain('email-not-verified');
    }
    expect($paths['/alerts/{variantKey}']['delete']['responses']['403']['description'])->toContain('demo-read-only')->not->toContain('email-not-verified');
});

it('documents closed request and response bodies', function (): void {
    $spec = t5Spec();
    $putAlert = t5Deref($spec, $spec['paths']['/alerts/{variantKey}']['put']['requestBody']['content']['application/json']['schema']);
    $putSettings = t5Deref($spec, $spec['paths']['/me/notifications']['put']['requestBody']['content']['application/json']['schema']);
    $alert = t5Body($spec, '/alerts/{variantKey}', 'put');
    $settings = t5Body($spec, '/me/notifications', 'get');
    $saved = t5Body($spec, '/me/notifications', 'put');

    expect(array_keys($putAlert['properties']))->toBe(['pct'])
        ->and($putAlert['properties']['pct']['type'])->toBe('integer')
        ->and($putAlert['required'])->toBe(['pct'])
        ->and(array_keys($putSettings['properties']))->toBe(['channel', 'digest'])
        ->and($putSettings['required'])->toBe(['channel', 'digest'])
        ->and($putSettings['properties']['channel']['enum'])->toBe(['email', 'discord'])
        ->and($putSettings['properties']['digest']['type'])->toBe('boolean');
    foreach ([$alert, $settings, $saved] as $body) {
        expect($body['additionalProperties'])->toBeFalse()
            ->and(t5Deref($spec, $body['properties']['data'])['additionalProperties'])->toBeFalse();
    }
    expect(array_keys(t5Deref($spec, $alert['properties']['data'])['properties']))->toBe(['variant_key', 'alert_pct'])
        ->and(array_keys(t5Deref($spec, $settings['properties']['data'])['properties']))->toBe(['channel', 'digest', 'discord_available'])
        ->and(array_keys(t5Deref($spec, $saved['properties']['data'])['properties']))->toBe(['channel', 'digest', 'discord_available']);
});

it('widens the watchlist item by alert_pct (last, integer or null) and leaves the holding unchanged', function (): void {
    $spec = t5Spec();
    $item = t5Deref($spec, t5Body($spec, '/watchlist', 'get')['properties']['data']['items']);
    $holding = t5Deref($spec, t5Body($spec, '/portfolio', 'get')['properties']['data']['items']);

    expect(array_key_last($item['properties']))->toBe('alert_pct')
        ->and($item['properties']['alert_pct']['type'])->toBe(['integer', 'null'])
        ->and(in_array('alert_pct', $item['required'], true))->toBeTrue()
        ->and(array_keys($holding['properties']))->not->toContain('alert_pct');
});

it('documents the export\'s nine keys, closed, and never a url, secret or body', function (): void {
    $spec = t5Spec();
    $data = t5Deref($spec, t5Body($spec, '/me/export', 'get')['properties']['data']);

    expect(array_keys($data['properties']))->toBe(['format', 'version', 'account', 'watchlist', 'portfolio', 'sessions', 'notifications', 'webhooks', 'deliveries'])
        ->and($data['additionalProperties'])->toBeFalse()
        ->and(array_keys($data['properties']['watchlist']['items']['properties']))->toBe(['variant_key', 'added_at', 'alert_pct'])
        ->and(array_keys($data['properties']['notifications']['properties']))->toBe(['channel', 'digest'])
        ->and(array_keys($data['properties']['webhooks']['items']['properties']))->toBe(['kind', 'url_hint', 'description', 'created_at', 'disabled'])
        ->and(array_keys($data['properties']['deliveries']['items']['properties']))->toBe(['kind', 'channel', 'day', 'status', 'attempts', 'created_at', 'delivered_at']);
});
