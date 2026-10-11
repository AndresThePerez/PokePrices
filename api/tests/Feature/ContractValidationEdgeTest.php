<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ContractValidator;

function edgeSpecValidator(): ContractValidator
{
    return ContractValidator::forSpec(base_path('../openapi/api.json'));
}

/**
 * Runs $callback with PP_C3_LEDGER pointing at a fresh file and returns the decoded ledger lines.
 *
 * @return list<array<string, mixed>>
 */
function edgeLedgerOf(Closure $callback): array
{
    $file = tempnam(sys_get_temp_dir(), 'c3edge');
    expect($file)->toBeString();
    $previous = getenv('PP_C3_LEDGER');
    putenv('PP_C3_LEDGER='.$file);
    try {
        $callback();
    } catch (AssertionFailedError) {
        // the lines written before the failure are still wanted
    } finally {
        putenv($previous === false ? 'PP_C3_LEDGER' : 'PP_C3_LEDGER='.$previous);
    }
    $lines = array_values(array_filter(explode("\n", (string) file_get_contents($file))));
    unlink($file);

    return array_map(fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);
}

it('fails a test whose /v1 route returns 200 JSON the contract does not document', function (): void {
    Route::get('/v1/zzz-not-in-contract', fn () => response()->json(['hello' => 'world']));

    $ledger = edgeLedgerOf(function (): void {
        $this->get('/v1/zzz-not-in-contract');
    });

    expect($ledger)->toHaveCount(1)
        ->and($ledger[0]['validated'])->toBeFalse()
        ->and($ledger[0]['reason'])->toBe('no_operation');
});

it('throws AssertionFailedError from the hook for an undocumented /v1 route', function (): void {
    Route::get('/v1/zzz-throws', fn () => response()->json(['hello' => 'world']));

    $thrown = null;
    edgeLedgerOf(function () use (&$thrown): void {
        try {
            $this->get('/v1/zzz-throws');
        } catch (AssertionFailedError $e) {
            $thrown = $e;
            throw $e;
        }
    });

    expect($thrown)->toBeInstanceOf(AssertionFailedError::class)
        ->and($thrown->getMessage())->toContain('no_operation');
});

it('rejects a 404 that is text/html for a /v1 path the contract lacks', function (): void {
    Route::get('/v1/zzz-html-404', fn () => response('<h1>gone</h1>', 404, ['Content-Type' => 'text/html']));

    $thrown = null;
    edgeLedgerOf(function () use (&$thrown): void {
        try {
            $this->get('/v1/zzz-html-404');
        } catch (AssertionFailedError $e) {
            $thrown = $e;
            throw $e;
        }
    });

    expect($thrown)->toBeInstanceOf(AssertionFailedError::class);
});

it('rejects a 200 on a documented path served as text/plain', function (): void {
    $bad = new Response('{"status":"ok","checks":{"database":"ok"}}', 200, ['Content-Type' => 'text/plain']);

    expect(edgeSpecValidator()->validate('GET', '/v1/ready', $bad)['validated'])->toBeFalse();
});

it('rejects a 200 readiness body missing the required checks key', function (): void {
    $bad = new Response('{"status":"ok"}', 200, ['Content-Type' => 'application/json']);

    expect(edgeSpecValidator()->validate('GET', '/v1/ready', $bad)['validated'])->toBeFalse();
});

it('rejects a 503 problem body missing detail and one carrying an extra key', function (): void {
    $base = ['type' => 't', 'title' => 'x', 'status' => 503, 'instance' => '/v1/ready', 'request_id' => 'r', 'detail' => 'd', 'checks' => ['database' => 'fail']];
    $missing = $base;
    unset($missing['detail']);
    $extra = $base + ['extra' => 1];
    $headers = ['Content-Type' => 'application/problem+json'];

    expect(edgeSpecValidator()->validate('GET', '/v1/ready', new Response(json_encode($base, JSON_THROW_ON_ERROR), 503, $headers))['validated'])->toBeTrue()
        ->and(edgeSpecValidator()->validate('GET', '/v1/ready', new Response(json_encode($missing, JSON_THROW_ON_ERROR), 503, $headers))['validated'])->toBeFalse()
        ->and(edgeSpecValidator()->validate('GET', '/v1/ready', new Response(json_encode($extra, JSON_THROW_ON_ERROR), 503, $headers))['validated'])->toBeFalse();
});

it('returns a failed result instead of throwing for a non-JSON body labelled application/json', function (): void {
    $bad = new Response('<<not json>>', 200, ['Content-Type' => 'application/json']);

    $result = edgeSpecValidator()->validate('GET', '/v1/ready', $bad);

    expect($result['validated'])->toBeFalse()->and($result['operation'])->toBe('get /ready');
});

it('strips the query string before matching and writes exactly one ledger line per request', function (): void {
    $ledger = edgeLedgerOf(function (): void {
        $this->get('/v1/ready?x=1');
        $this->get('/v1/ready');
        $this->get('/v1/nowhere');
    });

    expect($ledger)->toHaveCount(3);
    foreach ($ledger as $line) {
        expect(array_keys($line))->toBe(['method', 'path', 'status', 'operation', 'validated', 'reason']);
    }
    expect($ledger[0]['path'])->toBe('/v1/ready')
        ->and($ledger[0]['validated'])->toBeTrue()
        ->and($ledger[0]['operation'])->toBe('get /ready')
        ->and($ledger[2]['reason'])->toBe('unmatched_path_problem');
});

it('appends to an existing ledger instead of truncating it', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'c3edge');
    file_put_contents($file, "{\"seed\":true}\n");
    $previous = getenv('PP_C3_LEDGER');
    putenv('PP_C3_LEDGER='.$file);
    try {
        $this->get('/v1/ready');
    } finally {
        putenv($previous === false ? 'PP_C3_LEDGER' : 'PP_C3_LEDGER='.$previous);
    }
    $lines = array_values(array_filter(explode("\n", (string) file_get_contents($file))));
    unlink($file);

    expect($lines)->toHaveCount(2)->and($lines[0])->toBe('{"seed":true}');
});

it('accepts an undocumented method on a documented path only as a problem+json 405', function (): void {
    $ledger = edgeLedgerOf(function (): void {
        $this->post('/v1/ready');
    });

    expect($ledger)->toHaveCount(1)
        ->and($ledger[0]['status'])->toBe(405)
        ->and($ledger[0]['validated'])->toBeTrue()
        ->and($ledger[0]['reason'])->toBe('unmatched_path_problem');
});

it('logs a HEAD request as head_request and a /v1/__ route as test_route without validating the body', function (): void {
    Route::get('/v1/__edge-garbage', fn () => response('garbage', 200, ['Content-Type' => 'text/plain']));

    $ledger = edgeLedgerOf(function (): void {
        $this->call('HEAD', '/v1/ready');
        $this->get('/v1/__edge-garbage');
    });

    expect($ledger)->toHaveCount(2)
        ->and($ledger[0]['reason'])->toBe('head_request')
        ->and($ledger[1]['reason'])->toBe('test_route');
});

it('leaves non-/v1 responses out of the ledger', function (): void {
    $ledger = edgeLedgerOf(function (): void {
        $this->get('/up');
    });

    expect($ledger)->toBe([]);
});
