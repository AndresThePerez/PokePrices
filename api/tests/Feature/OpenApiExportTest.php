<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

function exportSpec(string $appUrl): string
{
    config(['app.url' => $appUrl]);
    $path = tempnam(sys_get_temp_dir(), 'pp-spec-');
    Artisan::call('scramble:export', ['--path' => $path]);
    $json = (string) file_get_contents($path);
    @unlink($path);

    return $json;
}

it('exports an OpenAPI 3.1 document titled PokéPrices with the readiness operation', function (): void {
    $spec = json_decode(exportSpec('http://pokeprices.localhost:8095'), true, 512, JSON_THROW_ON_ERROR);
    $paths = array_keys($spec['paths']);
    $ready = $spec['paths']['/ready']['get'] ?? $spec['paths']['/v1/ready']['get'] ?? null;
    expect($spec['openapi'])->toStartWith('3.1')
        ->and($spec['info']['title'])->toBe('PokéPrices')
        ->and($ready)->not->toBeNull()
        ->and(array_map('strval', array_keys($ready['responses'])))->toContain('200', '503')
        ->and($ready['responses']['503']['content'])->toHaveKey('application/problem+json')
        ->and(collect($paths)->filter(fn (string $p): bool => str_starts_with($p, '/api'))->all())->toBe([]);
});

it('exports byte-identical documents across runs and across APP_URL ports', function (): void {
    $a = exportSpec('http://pokeprices.localhost:8095');
    $b = exportSpec('http://pokeprices.localhost:8095');
    $c = exportSpec('http://pokeprices.localhost:18095');
    $spec = json_decode($a, true, 512, JSON_THROW_ON_ERROR);
    expect($a)->toBe($b)->and($a)->toBe($c)
        ->and($spec['info']['title'])->toBe('PokéPrices')
        ->and($a)->not->toContain('PokÃ©')
        ->and($spec['servers'])->toBe([['url' => '/v1']]);
});

it('matches the committed openapi/api.json', function (): void {
    expect(exportSpec('http://pokeprices.localhost:8095'))->toBe((string) file_get_contents(base_path('../openapi/api.json')));
});
