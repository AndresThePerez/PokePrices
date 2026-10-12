<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

// R157 proof: once a real route documents a 4xx, the exported document carries the Problem component.
it('exports components.schemas.Problem and problem+json once a throwaway route documents a 4xx and a 429', function (): void {
    Route::middleware(['api', 'throttle:60,1'])->prefix('v1')->get('__r157', function (Request $request): array {
        $request->validate(['q' => 'required|string']);

        return ['ok' => true];
    });
    $path = tempnam(sys_get_temp_dir(), 'pp-r157-');
    Artisan::call('scramble:export', ['--path' => $path]);
    $json = (string) file_get_contents($path);
    @unlink($path);
    $spec = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $operation = $spec['paths']['/__r157']['get'] ?? null;

    expect($operation)->not->toBeNull()
        ->and($spec['components']['schemas'])->toHaveKey('Problem')
        ->and($spec['components']['schemas']['Problem']['additionalProperties'])->toBeFalse()
        ->and($operation['responses']['422']['content'])->toHaveKey('application/problem+json')
        ->and($operation['responses']['422']['content']['application/problem+json']['schema'])->toBe(['$ref' => '#/components/schemas/Problem'])
        ->and($operation['responses']['429']['headers']['Retry-After']['required'])->toBeTrue()
        ->and($spec['paths']['/ready']['get']['responses']['503']['content']['application/problem+json']['schema'])->not->toHaveKey('$ref');
});
