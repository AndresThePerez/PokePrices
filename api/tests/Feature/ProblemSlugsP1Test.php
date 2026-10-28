<?php

declare(strict_types=1);

use App\Domain\Catalog\Problems\CardNotFound;
use App\Domain\Catalog\Problems\UpstreamContract;
use App\Domain\Catalog\Problems\UpstreamUnavailable;
use App\Domain\ProblemType;
use App\Http\Problems\ProblemDetails;
use App\OpenApi\ProblemSchema;
use cebe\openapi\spec\Schema;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use League\OpenAPIValidation\Schema\Exception\SchemaMismatch;
use League\OpenAPIValidation\Schema\SchemaValidator;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/** The Problem component from T32 as a validator schema; the tests below assert against it. */
function t33ProblemSchema(): Schema
{
    return new Schema(ProblemSchema::type()->toArray());
}

/** Validates a decoded body against the Problem schema and returns the mismatch message, or null when it conforms. */
function t33SchemaMismatch(mixed $body): ?string
{
    try {
        (new SchemaValidator)->validate($body, t33ProblemSchema());
    } catch (SchemaMismatch $mismatch) {
        return $mismatch->getMessage();
    }

    return null;
}

it('keeps the Retry-After and X-RateLimit headers of a throttle exception and answers too-many-requests', function (): void {
    Route::get('/v1/__t33_throttle', fn () => throw new ThrottleRequestsException('Too Many Attempts.', null, [
        'Retry-After' => 42,
        'X-RateLimit-Limit' => 60,
        'X-RateLimit-Remaining' => 0,
        'X-RateLimit-Reset' => 1760000042,
    ]));
    $response = $this->withHeader('X-Request-Id', 'req-t33-throttle-01')->get('/v1/__t33_throttle');

    expect($response->getStatusCode())->toBe(429)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->headers->get('Retry-After'))->toBe('42')
        ->and($response->headers->get('X-RateLimit-Limit'))->toBe('60')
        ->and($response->headers->get('X-RateLimit-Remaining'))->toBe('0')
        ->and($response->headers->get('X-RateLimit-Reset'))->toBe('1760000042')
        ->and($response->json('type'))->toBe('https://pokeprices.andrestheperez.com/problems/too-many-requests')
        ->and($response->json('title'))->toBe('Too Many Requests')
        ->and($response->json('status'))->toBe(429)
        ->and($response->json('instance'))->toBe('/v1/__t33_throttle')
        ->and($response->json('request_id'))->toBe('req-t33-throttle-01')
        ->and($response->headers->get('X-Request-Id'))->toBe('req-t33-throttle-01')
        ->and(t33SchemaMismatch($response->json()))->toBeNull();
});

it('maps any 429 http exception, not only Laravel throttle, to too-many-requests with its headers', function (): void {
    Route::get('/v1/__t33_429', fn () => throw new TooManyRequestsHttpException(7));
    $response = $this->get('/v1/__t33_429');

    expect($response->getStatusCode())->toBe(429)
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and($response->headers->get('Retry-After'))->toBe('7');
});

it('answers UpstreamUnavailable with 503 upstream-unavailable and an integer Retry-After', function (): void {
    Route::get('/v1/__t33_unavailable', fn () => throw new UpstreamUnavailable(30, new RuntimeException('sentinel-upstream-host-detail')));
    $response = $this->withHeader('X-Request-Id', 'req-t33-unavail-01')->get('/v1/__t33_unavailable');

    expect($response->getStatusCode())->toBe(503)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->headers->get('Retry-After'))->toBe('30')
        ->and($response->json('type'))->toBe('https://pokeprices.andrestheperez.com/problems/upstream-unavailable')
        ->and($response->json('title'))->toBe('Upstream Unavailable')
        ->and($response->json('status'))->toBe(503)
        ->and($response->json('request_id'))->toBe('req-t33-unavail-01')
        ->and($response->getContent())->not->toContain('sentinel-upstream-host-detail')
        ->and(t33SchemaMismatch($response->json()))->toBeNull();
});

it('never sends a Retry-After below one second for UpstreamUnavailable', function (int $given): void {
    $exception = new UpstreamUnavailable($given);
    $response = ProblemDetails::fromThrowable($exception, Request::create('/v1/cards'));

    expect($response->getStatusCode())->toBe(503)
        ->and($response->headers->get('Retry-After'))->toBe('1')
        ->and($exception->getHeaders())->toBe(['Retry-After' => '1']);
})->with([0, -5]);

it('answers UpstreamContract with 502 upstream-contract', function (): void {
    Route::get('/v1/__t33_contract', fn () => throw new UpstreamContract('sentinel-upstream-shape'));
    $response = $this->withHeader('X-Request-Id', 'req-t33-contract-01')->get('/v1/__t33_contract');

    expect($response->getStatusCode())->toBe(502)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->headers->has('Retry-After'))->toBeFalse()
        ->and($response->json('type'))->toBe('https://pokeprices.andrestheperez.com/problems/upstream-contract')
        ->and($response->json('title'))->toBe('Upstream Contract Violation')
        ->and($response->json('status'))->toBe(502)
        ->and($response->json('request_id'))->toBe('req-t33-contract-01')
        ->and($response->getContent())->not->toContain('sentinel-upstream-shape')
        ->and(t33SchemaMismatch($response->json()))->toBeNull();
});

it('answers CardNotFound with 404 card-not-found and a boolean catalog_missing', function (bool $missing): void {
    Route::get('/v1/__t33_card', fn () => throw new CardNotFound($missing));
    $response = $this->withHeader('X-Request-Id', 'req-t33-card-0001')->get('/v1/__t33_card');

    expect($response->getStatusCode())->toBe(404)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe('https://pokeprices.andrestheperez.com/problems/card-not-found')
        ->and($response->json('title'))->toBe('Card Not Found')
        ->and($response->json('status'))->toBe(404)
        ->and($response->json('request_id'))->toBe('req-t33-card-0001')
        ->and($response->json('catalog_missing'))->toBe($missing)
        ->and(t33SchemaMismatch($response->json()))->toBeNull();
})->with([true, false]);

it('lets problem+json win over a Content-Type the exception carries and keeps its other headers', function (string $name): void {
    Route::get('/v1/__t33_ctype', fn () => throw new HttpException(409, '', null, [$name => 'text/html', 'X-PP-Probe' => 'kept']));
    $response = $this->get('/v1/__t33_ctype');

    expect($response->getStatusCode())->toBe(409)
        ->and($response->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($response->headers->get('X-PP-Probe'))->toBe('kept')
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'http-409')
        ->and($response->json('title'))->toBe('Conflict')
        ->and(t33SchemaMismatch($response->json()))->toBeNull();
})->with(['Content-Type', 'content-type']);

it('keeps http-<n> for the other statuses, 502 and 503 included, when no P1 exception is thrown', function (): void {
    Route::get('/v1/__t33_a502', fn () => abort(502));
    Route::get('/v1/__t33_a503', fn () => abort(503));
    $r502 = $this->get('/v1/__t33_a502');
    $r503 = $this->get('/v1/__t33_a503');

    expect($r502->json('type'))->toBe(ProblemDetails::BASE.'http-502')
        ->and($r503->json('type'))->toBe(ProblemDetails::BASE.'http-503')
        ->and($r503->headers->has('Retry-After'))->toBeFalse();
});

it('checks every P1 body against the Problem schema, which rejects a body with an undeclared member', function (): void {
    $request = Request::create('/v1/cards/base1-4');
    $bodies = array_map(
        fn (Throwable $e): mixed => json_decode((string) ProblemDetails::fromThrowable($e, $request)->getContent(), true),
        [new ThrottleRequestsException('', null, ['Retry-After' => 42]), new UpstreamUnavailable(5), new UpstreamContract, new CardNotFound(true)],
    );

    expect(array_map(t33SchemaMismatch(...), $bodies))->toBe([null, null, null, null])
        ->and(array_column($bodies, 'type'))->toBe([
            ProblemDetails::BASE.'too-many-requests',
            ProblemDetails::BASE.'upstream-unavailable',
            ProblemDetails::BASE.'upstream-contract',
            ProblemDetails::BASE.'card-not-found',
        ])
        ->and(t33SchemaMismatch($bodies[3] + ['unexpected' => 'x']))->not->toBeNull()
        ->and(t33SchemaMismatch(['catalog_missing' => 'yes'] + $bodies[3]))->not->toBeNull();
});

it('renders any ProblemType with its own slug, title and extra members instead of http-<n> (Q1-i4 T04, A9)', function (): void {
    $problem = new class extends HttpException implements ProblemType
    {
        public function __construct()
        {
            parent::__construct(409, 'sentinel-probe-message');
        }

        public function slug(): string
        {
            return 'probe-conflict';
        }

        public function title(): string
        {
            return 'Probe Conflict';
        }

        public function extra(): array
        {
            return ['probe_flag' => true];
        }
    };
    $response = ProblemDetails::fromThrowable($problem, Request::create('/v1/__t04_probe'));

    expect($response->getStatusCode())->toBe(409)
        ->and($response->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($response->getData(true))->toBe([
            'type' => ProblemDetails::BASE.'probe-conflict',
            'title' => 'Probe Conflict',
            'status' => 409,
            'instance' => '/v1/__t04_probe',
            'request_id' => '',
            'probe_flag' => true,
        ]);
});
