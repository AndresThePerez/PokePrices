<?php

declare(strict_types=1);

use App\Domain\Alerts\Problems\WebhookDisabled;
use App\Domain\Alerts\Problems\WebhookNotFound;
use App\Domain\Catalog\Problems\CardNotFound;
use App\Domain\Catalog\Problems\UpstreamContract;
use App\Domain\Catalog\Problems\UpstreamUnavailable;
use App\Domain\ProblemType;
use App\Http\Problems\AlreadySignedIn;
use App\Http\Problems\DemoReadOnly;
use App\Http\Problems\DemoUnavailable;
use App\Http\Problems\EmailNotVerified;
use App\Http\Problems\ProblemDetails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Reviewer tests for Q1-i4 T04: the problem classes moved namespaces and now name their own slug, title and extras
// through ProblemType. Every expected value below is the literal the closed match in ProblemDetails produced at
// 3684346, so a regression in any moved or re-wired class shows as a changed byte.

/** A ProblemType HttpException with the given status, slug, title and extras (the shape a new problem class takes). */
function q1t04rvProblem(int $status, string $slug = 'probe', string $title = 'Probe', array $extra = [], array $headers = []): HttpException
{
    return new class($status, $slug, $title, $extra, $headers) extends HttpException implements ProblemType
    {
        /** @param array<string, mixed> $extra */
        public function __construct(int $status, private readonly string $slug, private readonly string $title, private readonly array $extra, array $headers)
        {
            parent::__construct($status, 'sentinel-message-not-for-the-body', null, $headers);
        }

        public function slug(): string
        {
            return $this->slug;
        }

        public function title(): string
        {
            return $this->title;
        }

        public function extra(): array
        {
            return $this->extra;
        }
    };
}

it('renders every PokéPrices problem class through the real handler with the type, title, status and extras it had before the move', function (Closure $make, int $status, string $slug, string $title, array $extra, array $headers): void {
    Route::get('/v1/__q1t04rv_parity', fn () => throw $make());
    $response = $this->getJson('/v1/__q1t04rv_parity');
    $body = $response->json();
    $requestId = $body['request_id'] ?? null;

    expect($response->getStatusCode())->toBe($status)
        ->and($response->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($requestId)->toBeString()->not->toBe('')
        ->and($body)->toBe([
            'type' => 'https://pokeprices.andrestheperez.com/problems/'.$slug,
            'title' => $title,
            'status' => $status,
            'instance' => '/v1/__q1t04rv_parity',
            'request_id' => $requestId,
        ] + $extra)
        ->and(array_key_exists('detail', $body))->toBeFalse()
        ->and($response->getContent())->not->toContain('sentinel-leak-0000');
    foreach ($headers as $name => $value) {
        expect($response->headers->get($name))->toBe($value);
    }
})->with([
    'card not found' => [fn () => new CardNotFound, 404, 'card-not-found', 'Card Not Found', ['catalog_missing' => false], []],
    'card not found, catalog missing' => [fn () => new CardNotFound(true), 404, 'card-not-found', 'Card Not Found', ['catalog_missing' => true], []],
    'upstream contract with a message' => [fn () => new UpstreamContract('sentinel-leak-0000'), 502, 'upstream-contract', 'Upstream Contract Violation', [], []],
    'upstream unavailable' => [fn () => new UpstreamUnavailable(7, new RuntimeException('sentinel-leak-0000')), 503, 'upstream-unavailable', 'Upstream Unavailable', [], ['Retry-After' => '7']],
    'webhook not found' => [fn () => new WebhookNotFound, 404, 'webhook-not-found', 'Webhook Not Found', [], []],
    'webhook disabled' => [fn () => new WebhookDisabled, 409, 'webhook-disabled', 'Webhook Turned Off', [], []],
    'already signed in' => [fn () => new AlreadySignedIn, 409, 'already-signed-in', 'Already Signed In', [], []],
    'demo read only' => [fn () => new DemoReadOnly, 403, 'demo-read-only', 'Demo Account Is Read-Only', [], []],
    'demo unavailable' => [fn () => new DemoUnavailable, 404, 'demo-unavailable', 'Demo Account Unavailable', [], []],
    'email not verified' => [fn () => new EmailNotVerified, 403, 'email-not-verified', 'Verify Your Email First', [], []],
]);

it('lets a problem type win over the 404, 405, 419 and 429 status handling while its exception headers still travel', function (int $status): void {
    $response = ProblemDetails::fromThrowable(
        q1t04rvProblem($status, 'own-slug-'.$status, 'Own Title '.$status, ['own' => $status], ['X-Probe' => 'kept']),
        Request::create('/v1/__q1t04rv_precedence'),
    );

    expect($response->getStatusCode())->toBe($status)
        ->and($response->getData(true)['type'])->toBe(ProblemDetails::BASE.'own-slug-'.$status)
        ->and($response->getData(true)['title'])->toBe('Own Title '.$status)
        ->and($response->getData(true)['own'])->toBe($status)
        ->and($response->headers->get('X-Probe'))->toBe('kept')
        ->and($response->headers->get('Content-Type'))->toBe('application/problem+json');
})->with([404, 405, 419, 429, 418, 500, 503]);

it('keeps the 404, 405, 419, 429 and http-<n> slugs for exceptions that are not problem types', function (Throwable $e, int $status, string $slug, string $title): void {
    $body = ProblemDetails::fromThrowable($e, Request::create('/v1/__q1t04rv_plain'))->getData(true);

    expect($body['status'])->toBe($status)
        ->and($body['type'])->toBe(ProblemDetails::BASE.$slug)
        ->and($body['title'])->toBe($title)
        ->and(array_keys($body))->toBe(['type', 'title', 'status', 'instance', 'request_id'])
        ->and(json_encode($body))->not->toContain('sentinel-leak-0000');
})->with([
    'not found http exception' => [new NotFoundHttpException('sentinel-leak-0000'), 404, 'not-found', 'Not Found'],
    'bare 404 http exception' => [new HttpException(404, 'sentinel-leak-0000'), 404, 'http-404', 'Not Found'],
    'method not allowed' => [new MethodNotAllowedHttpException(['GET'], 'sentinel-leak-0000'), 405, 'method-not-allowed', 'Method Not Allowed'],
    'csrf mismatch' => [new HttpException(419, 'sentinel-leak-0000'), 419, 'csrf-token-mismatch', 'CSRF Token Mismatch'],
    'throttled' => [new HttpException(429, 'sentinel-leak-0000'), 429, 'too-many-requests', 'Too Many Requests'],
    'conflict that is not a problem type' => [new HttpException(409, 'sentinel-leak-0000'), 409, 'http-409', 'Conflict'],
    'teapot' => [new HttpException(418, 'sentinel-leak-0000'), 418, 'http-418', "I'm a teapot"],
    'unknown status text' => [new HttpException(599, 'sentinel-leak-0000'), 599, 'http-599', 'Error'],
    'non-http exception' => [new RuntimeException('sentinel-leak-0000'), 500, 'server-error', 'Server Error'],
]);

it('never lets a problem type\'s extra members replace the standard members', function (): void {
    $body = ProblemDetails::fromThrowable(
        q1t04rvProblem(409, 'real-slug', 'Real Title', [
            'type' => 'https://example.com/forged', 'title' => 'Forged', 'status' => 200, 'instance' => '/forged', 'request_id' => 'forged',
            'detail' => 'sentinel-leak-0000', 'added' => 1,
        ]),
        Request::create('/v1/__q1t04rv_shadow'),
    )->getData(true);

    expect($body['type'])->toBe(ProblemDetails::BASE.'real-slug')
        ->and($body['title'])->toBe('Real Title')
        ->and($body['status'])->toBe(409)
        ->and($body['instance'])->toBe('/v1/__q1t04rv_shadow')
        ->and($body['request_id'])->toBe('')
        ->and($body['added'])->toBe(1);
});
