<?php

declare(strict_types=1);

use App\Http\Problems\CardNotFound;
use App\Http\Problems\ProblemDetails;
use App\Http\Problems\UpstreamContract;
use App\Http\Problems\UpstreamUnavailable;
use App\OpenApi\ProblemSchema;
use cebe\openapi\spec\Schema;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use League\OpenAPIValidation\Schema\Exception\SchemaMismatch;
use League\OpenAPIValidation\Schema\SchemaValidator;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Returns the Problem-schema mismatch message for a decoded body, or null when it conforms. */
function t33EdgeMismatch(mixed $body): ?string
{
    try {
        (new SchemaValidator)->validate($body, new Schema(ProblemSchema::type()->toArray()));
    } catch (SchemaMismatch $mismatch) {
        return $mismatch->getMessage();
    }

    return null;
}

it('answers UpstreamUnavailable with an integer Retry-After of at least one for every integer input', function (int $given, string $expected): void {
    Route::get('/v1/__t33e_unavail', fn () => throw new UpstreamUnavailable($given));
    $response = $this->get('/v1/__t33e_unavail');

    expect($response->getStatusCode())->toBe(503)
        ->and($response->headers->get('Retry-After'))->toBe($expected)
        ->and($response->headers->get('Retry-After'))->toMatch('/\A[1-9][0-9]*\z/')
        ->and($response->headers->all('retry-after'))->toHaveCount(1)
        ->and(t33EdgeMismatch($response->json()))->toBeNull();
})->with([
    'zero' => [0, '1'],
    'one' => [1, '1'],
    'two' => [2, '2'],
    'negative' => [-1, '1'],
    'int min' => [PHP_INT_MIN, '1'],
    'int max' => [PHP_INT_MAX, (string) PHP_INT_MAX],
]);

it('refuses a non-integer Retry-After for UpstreamUnavailable instead of sending a malformed header', function (mixed $given): void {
    expect(fn () => new UpstreamUnavailable($given))->toThrow(TypeError::class);
})->with(['float' => [1.5], 'text' => ['abc'], 'http date' => ['Wed, 21 Oct 2026 07:28:00 GMT'], 'null' => [null]]);

it('keeps array-valued and mixed-case exception headers, drops unusable values and never lets Content-Type through', function (): void {
    Route::get('/v1/__t33e_headers', fn () => throw new HttpException(418, '', null, [
        'X-Multi' => ['one', 'two'],
        'x-lower-case' => 'lowered',
        'X-Number' => 7,
        'X-Empty-List' => [],
        'X-Bad-Item' => [['nested']],
        'CONTENT-TYPE' => 'text/html; charset=utf-8',
    ]));
    $response = $this->get('/v1/__t33e_headers');

    expect($response->getStatusCode())->toBe(418)
        ->and($response->headers->all('x-multi'))->toBe(['one', 'two'])
        ->and($response->headers->get('X-Lower-Case'))->toBe('lowered')
        ->and($response->headers->get('X-Number'))->toBe('7')
        ->and($response->headers->has('X-Empty-List'))->toBeFalse()
        ->and($response->headers->has('X-Bad-Item'))->toBeFalse()
        ->and($response->headers->all('content-type'))->toBe(['application/problem+json'])
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'http-418')
        ->and(t33EdgeMismatch($response->json()))->toBeNull();
});

it('lets the request id from the request win over an X-Request-Id carried by the exception', function (): void {
    Route::get('/v1/__t33e_spoof', fn () => throw new HttpException(409, '', null, ['X-Request-Id' => 'spoofed-by-exception', 'X-PokePrices-Origin' => 'spoofed']));
    $response = $this->withHeader('X-Request-Id', 'req-t33e-spoof-01')->get('/v1/__t33e_spoof');

    expect($response->headers->get('X-Request-Id'))->toBe('req-t33e-spoof-01')
        ->and($response->headers->get('X-PokePrices-Origin'))->toBe('api')
        ->and($response->json('request_id'))->toBe('req-t33e-spoof-01')
        ->and($response->getContent())->not->toContain('spoofed');
});

it('passes X-RateLimit headers through unchanged and does not leak them into the next request', function (): void {
    Route::get('/v1/__t33e_limited', fn () => throw new ThrottleRequestsException('Too Many Attempts.', null, [
        'Retry-After' => 3, 'X-RateLimit-Limit' => 120, 'X-RateLimit-Remaining' => 0, 'X-RateLimit-Reset' => 1760000003,
    ]));
    Route::get('/v1/__t33e_plain404', fn () => throw new NotFoundHttpException);
    $limited = $this->get('/v1/__t33e_limited');
    $after = $this->get('/v1/__t33e_plain404');

    expect($limited->getStatusCode())->toBe(429)
        ->and($limited->headers->get('Retry-After'))->toBe('3')
        ->and($limited->headers->get('X-RateLimit-Limit'))->toBe('120')
        ->and($limited->headers->get('X-RateLimit-Remaining'))->toBe('0')
        ->and($limited->headers->get('X-RateLimit-Reset'))->toBe('1760000003')
        ->and($after->getStatusCode())->toBe(404)
        ->and($after->json('type'))->toBe(ProblemDetails::BASE.'not-found')
        ->and($after->headers->has('Retry-After'))->toBeFalse()
        ->and($after->headers->has('X-RateLimit-Limit'))->toBeFalse();
});

it('maps a bare HttpException 429 without headers to too-many-requests and sends no Retry-After', function (): void {
    Route::get('/v1/__t33e_bare429', fn () => throw new HttpException(429));
    $response = $this->get('/v1/__t33e_bare429');

    expect($response->getStatusCode())->toBe(429)
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and($response->json('title'))->toBe('Too Many Requests')
        ->and($response->headers->has('Retry-After'))->toBeFalse()
        ->and(t33EdgeMismatch($response->json()))->toBeNull();
});

it('does not turn a generic 404 or 503 HttpException into a P1 slug, and a 503 keeps only the headers it carries', function (): void {
    Route::get('/v1/__t33e_g404', fn () => throw new HttpException(404, 'sentinel-missing'));
    Route::get('/v1/__t33e_g503', fn () => throw new HttpException(503));
    $g404 = $this->get('/v1/__t33e_g404');
    $g503 = $this->get('/v1/__t33e_g503');

    expect($g404->json('type'))->toBe(ProblemDetails::BASE.'http-404')
        ->and($g404->json())->not->toHaveKey('catalog_missing')
        ->and($g404->getContent())->not->toContain('sentinel-missing')
        ->and($g503->json('type'))->toBe(ProblemDetails::BASE.'http-503')
        ->and($g503->headers->has('Retry-After'))->toBeFalse();
});

it('serialises catalog_missing as a JSON boolean literal and carries no other extension member', function (bool $missing): void {
    $response = ProblemDetails::fromThrowable(new CardNotFound($missing), Request::create('/v1/cards/sv1-001'));
    $raw = (string) $response->getContent();
    $body = json_decode($raw, true);

    expect($raw)->toContain($missing ? '"catalog_missing":true' : '"catalog_missing":false')
        ->and(array_keys($body))->toBe(['type', 'title', 'status', 'instance', 'request_id', 'catalog_missing'])
        ->and($body['catalog_missing'])->toBe($missing)
        ->and($body['instance'])->toBe('/v1/cards/sv1-001')
        ->and(t33EdgeMismatch($body))->toBeNull();
})->with([true, false]);

it('keeps the previous exception message and a non-ASCII upstream message out of every P1 body', function (): void {
    $request = Request::create('/v1/cards?q=x');
    $previous = new RuntimeException('sentinel-prev-http://192.0.2.7:8081/secret');
    $exceptions = [
        new UpstreamUnavailable(9, $previous),
        new UpstreamContract('sentinel-contract-é-'.str_repeat('x', 5000), $previous),
        new CardNotFound(false, $previous),
    ];
    foreach ($exceptions as $e) {
        $content = (string) ProblemDetails::fromThrowable($e, $request)->getContent();
        expect($content)->not->toContain('sentinel')
            ->and($content)->not->toContain('192.0.2.7')
            ->and(strlen($content))->toBeLessThan(600)
            ->and(json_decode($content, true))->not->toHaveKey('detail');
    }
});

it('keeps the P0 mappings unchanged, adds Allow to a 405 and leaks no headers on a plain 500', function (): void {
    Route::get('/v1/__t33e_only_get', fn () => 'ok');
    Route::get('/v1/__t33e_unauth', fn () => throw new AuthenticationException);
    Route::get('/v1/__t33e_boom', fn () => throw new RuntimeException('sentinel-boom'));
    Route::post('/v1/__t33e_validate', function (Request $request): never {
        $request->validate(['name' => 'required']);
        throw new LogicException('unreachable');
    });
    $notFound = $this->get('/v1/__t33e_missing_route');
    $notAllowed = $this->post('/v1/__t33e_only_get');
    $unauth = $this->get('/v1/__t33e_unauth');
    $boom = $this->get('/v1/__t33e_boom');
    $invalid = $this->postJson('/v1/__t33e_validate', []);

    expect($notFound->getStatusCode())->toBe(404)
        ->and($notFound->json('type'))->toBe(ProblemDetails::BASE.'not-found')
        ->and($notAllowed->getStatusCode())->toBe(405)
        ->and($notAllowed->json('type'))->toBe(ProblemDetails::BASE.'method-not-allowed')
        ->and($notAllowed->headers->get('Allow'))->toContain('GET')
        ->and($notAllowed->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($unauth->getStatusCode())->toBe(401)
        ->and($unauth->json('type'))->toBe(ProblemDetails::BASE.'unauthenticated')
        ->and($invalid->getStatusCode())->toBe(422)
        ->and($invalid->json('errors.name'))->toBeArray()->not->toBeEmpty()
        ->and($boom->getStatusCode())->toBe(500)
        ->and($boom->json('type'))->toBe(ProblemDetails::BASE.'server-error')
        ->and($boom->getContent())->not->toContain('sentinel-boom')
        ->and($boom->headers->has('Retry-After'))->toBeFalse()
        ->and($boom->headers->has('Allow'))->toBeFalse()
        ->and($boom->headers->has('X-RateLimit-Limit'))->toBeFalse();
});
