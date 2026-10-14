<?php

declare(strict_types=1);

// Plan P5-i1 T03 criterion 8 (D9): the four webhook operations in the committed contract (openapi/api.json) with
// their documented statuses, the 403 of webhooks.store naming both problem types, closed schemas, and secret only in
// the 201 schema of webhooks.store.

/** @return array<string, mixed> */
function t3Spec(): array
{
    return json_decode((string) file_get_contents(base_path('../openapi/api.json')), true, 512, JSON_THROW_ON_ERROR);
}

/** Resolves every local $ref in $node against $spec, recursively. */
function t3Resolve(array $spec, mixed $node, int $depth = 0): mixed
{
    if (! is_array($node) || $depth > 20) {
        return $node;
    }
    if (isset($node['$ref']) && is_string($node['$ref'])) {
        $target = $spec;
        foreach (explode('/', substr($node['$ref'], 2)) as $part) {
            $target = $target[$part];
        }

        return t3Resolve($spec, $target, $depth + 1);
    }

    return array_map(fn (mixed $child): mixed => t3Resolve($spec, $child, $depth + 1), $node);
}

/** @return list<string> every property name in a resolved schema tree */
function t3Properties(mixed $node): array
{
    if (! is_array($node)) {
        return [];
    }
    $names = isset($node['properties']) && is_array($node['properties']) ? array_map('strval', array_keys($node['properties'])) : [];
    foreach ($node as $child) {
        $names = [...$names, ...t3Properties($child)];
    }

    return $names;
}

it('documents the four webhook operations with exactly their statuses', function (): void {
    $paths = t3Spec()['paths'];
    $codes = fn (string $path, string $method): array => array_map('strval', array_keys($paths[$path][$method]['responses']));

    expect(array_keys($paths['/webhooks']))->toBe(['get', 'post'])
        ->and(array_keys($paths['/webhooks/{id}']))->toBe(['delete'])
        ->and(array_keys($paths['/webhooks/{id}/test']))->toBe(['post'])
        ->and($codes('/webhooks', 'get'))->toBe(['200', '401', '429'])
        ->and($codes('/webhooks', 'post'))->toBe(['201', '401', '403', '419', '422', '429'])
        ->and($codes('/webhooks/{id}', 'delete'))->toBe(['204', '401', '403', '419', '429'])
        ->and($codes('/webhooks/{id}/test', 'post'))->toBe(['202', '401', '403', '404', '409', '419', '429'])
        ->and([
            $paths['/webhooks']['get']['operationId'], $paths['/webhooks']['post']['operationId'],
            $paths['/webhooks/{id}']['delete']['operationId'], $paths['/webhooks/{id}/test']['post']['operationId'],
        ])->toBe(['getWebhooks', 'createWebhook', 'deleteWebhook', 'testWebhook']);
});

it('names both demo-read-only and email-not-verified in the 403 of the gated operations, and only demo-read-only on delete', function (): void {
    $paths = t3Spec()['paths'];

    expect($paths['/webhooks']['post']['responses']['403']['description'])->toContain('/problems/demo-read-only')
        ->and($paths['/webhooks']['post']['responses']['403']['description'])->toContain('/problems/email-not-verified')
        ->and($paths['/webhooks/{id}/test']['post']['responses']['403']['description'])->toContain('/problems/email-not-verified')
        ->and($paths['/webhooks/{id}']['delete']['responses']['403']['description'])->not->toContain('email-not-verified');
});

it('closes the endpoint schemas and carries secret only in the 201 of webhooks.store', function (): void {
    $spec = t3Spec();
    $created = t3Resolve($spec, $spec['paths']['/webhooks']['post']['responses']['201']['content']['application/json']['schema']);
    $index = t3Resolve($spec, $spec['paths']['/webhooks']['get']['responses']['200']['content']['application/json']['schema']);
    $item = $index['properties']['data']['items'];
    $others = [];
    foreach ($spec['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            if ("{$method} {$path}" === 'post /webhooks') {
                $others[] = t3Resolve($spec, array_diff_key($operation['responses'], ['201' => true]));

                continue;
            }
            $others[] = t3Resolve($spec, $operation['responses']);
        }
    }

    expect(array_keys($created['properties']['data']['properties']))->toBe(['id', 'kind', 'url_hint', 'description', 'created_at', 'disabled', 'last_delivery', 'secret'])
        ->and($created['properties']['data']['additionalProperties'] ?? null)->toBeFalse()
        ->and(array_keys($item['properties']))->toBe(['id', 'kind', 'url_hint', 'description', 'created_at', 'disabled', 'last_delivery'])
        ->and($item['additionalProperties'] ?? null)->toBeFalse()
        ->and(array_keys($index['properties']['meta']['properties']))->toBe(['count', 'limit'])
        ->and(in_array('secret', t3Properties($others), true))->toBeFalse()
        ->and(in_array('url', t3Properties(t3Resolve($spec, $spec['paths']['/webhooks']['get']['responses'])), true))->toBeFalse();
});
