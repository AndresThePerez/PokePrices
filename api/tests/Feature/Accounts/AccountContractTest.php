<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

// Plan P4-i1 T02 criterion 11 (D4: the /v1 contract documents GET /v1/me and holds no Fortify or Sanctum path)
// and criterion 16 (review F-P4-03: no credential in a URL).

/** @return array<string, mixed> */
function accountExport(): array
{
    $path = tempnam(sys_get_temp_dir(), 'pp-accounts-spec-');
    Artisan::call('scramble:export', ['--path' => $path]);
    $spec = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    @unlink($path);

    return $spec;
}

it('documents GET /v1/me with 200, 401 and 429, a closed data object of the four account fields', function (): void {
    $spec = accountExport();
    $me = $spec['paths']['/me']['get'] ?? null;

    expect($me)->not->toBeNull()
        ->and(array_map('strval', array_keys($me['responses'])))->toBe(['200', '401', '429'])
        ->and($me['responses']['401']['content'])->toHaveKey('application/problem+json')
        ->and($me['responses']['429']['content'])->toHaveKey('application/problem+json')
        ->and($me['responses']['429']['headers'])->toHaveKey('Retry-After');
    $schema = $me['responses']['200']['content']['application/json']['schema'];
    $ref = $schema['properties']['data']['$ref'] ?? null;
    $data = is_string($ref) ? $spec['components']['schemas'][substr($ref, strlen('#/components/schemas/'))] : $schema['properties']['data'];
    expect($schema['required'])->toBe(['data'])
        ->and($schema['additionalProperties'])->toBeFalse()
        ->and($me['responses']['401']['description'])->toBe('Unauthenticated: sign in first (problem type https://pokeprices.andrestheperez.com/problems/unauthenticated).');
    expect(array_keys($data['properties']))->toBe(['email', 'email_verified', 'two_factor', 'read_only', 'portfolio'])
        ->and($data['required'])->toBe(['email', 'email_verified', 'two_factor', 'read_only', 'portfolio'])
        ->and($data['additionalProperties'])->toBeFalse()
        ->and($data['properties']['email']['type'])->toBe('string')
        ->and($data['properties']['email_verified']['type'])->toBe('boolean')
        ->and($data['properties']['two_factor']['type'])->toBe('boolean')
        ->and($data['properties']['read_only']['type'])->toBe('boolean');
});

it('holds no Fortify or Sanctum path in the /v1 contract', function (): void {
    $paths = array_keys(accountExport()['paths']);
    $session = '#^/(v1/)?(login|logout|register|forgot-password|reset-password|email/|user/|two-factor-challenge|sanctum/)#';

    expect(array_values(array_filter($paths, fn (string $p): bool => preg_match($session, $p) === 1)))->toBe([]);
});

it('never takes a password in a URL: no parameter is named password and no write puts fields in its query (F3)', function (): void {
    $spec = accountExport();

    foreach ($spec['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            $parameters = $operation['parameters'] ?? [];
            expect(array_filter($parameters, fn (array $p): bool => strtolower((string) ($p['name'] ?? '')) === 'password'))->toBe([], "{$method} {$path} takes a password parameter");
            if ($method !== 'get' && $method !== 'head') {
                $queryFields = array_filter($parameters, fn (array $p): bool => ($p['in'] ?? '') === 'query');
                expect($queryFields)->toBe([], "{$method} {$path} documents validated fields outside a request body");
            }
        }
    }
    expect($spec['paths'])->not->toBe([]);
});
