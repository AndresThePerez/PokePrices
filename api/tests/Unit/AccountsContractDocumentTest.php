<?php

declare(strict_types=1);

use App\OpenApi\ContractDocument;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\InfoObject;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Path;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

// Plan P4-i1 T02 criterion 11 (D4): ContractDocument adds 401 to auth/auth:* routes and 419 to non-GET routes
// carrying EnsureFrontendRequestsAreStateful, by route metadata. Synthetic routes and documents, as in
// ContractDocumentP1Test.

function accRouter(Closure $routes): Router
{
    $router = new Router(new Dispatcher(new Container), new Container);
    $router->aliasMiddleware('auth', Authenticate::class);
    $routes($router);

    return $router;
}

/**
 * @param  list<Operation>  $operations
 * @return array<string, mixed>
 */
function accTransform(Router $router, array $operations): array
{
    $openApi = OpenApi::make('3.1.0')->setComponents(new Components)->setInfo(InfoObject::make('PokéPrices')->setVersion('0.1.0'));
    foreach ($operations as $operation) {
        $openApi->addPath(Path::make(substr($operation->path, 3))->addOperation($operation));
    }
    (new ContractDocument($router))($openApi);

    return json_decode((string) json_encode($openApi->toArray()), true, 512, JSON_THROW_ON_ERROR);
}

function accOperation(string $path, string $method = 'get'): Operation
{
    $ok = Response::make(200)->setDescription('OK')->setContent('application/json', Schema::fromType(
        (new ObjectType)->addProperty('data', new StringType)->setRequired(['data'])
    ));

    return Operation::make($method)->setPath($path)->setOperationId('op'.md5($method.$path))->addResponse($ok);
}

/** @return list<string> */
function accCodes(array $spec, string $path, string $method): array
{
    return array_map('strval', array_keys($spec['paths'][$path][$method]['responses']));
}

it('adds the 401 unauthenticated problem to routes carrying auth or auth:<guard>, and only to them', function (): void {
    $router = accRouter(function (Router $r): void {
        $r->get('v1/me', fn (): null => null)->middleware('auth:sanctum');
        $r->get('v1/plain-auth', fn (): null => null)->middleware('auth');
        $r->get('v1/by-class', fn (): null => null)->middleware(Authenticate::class.':web');
        $r->get('v1/public', fn (): null => null);
    });

    $spec = accTransform($router, [accOperation('v1/me'), accOperation('v1/plain-auth'), accOperation('v1/by-class'), accOperation('v1/public')]);
    $unauthenticated = $spec['paths']['/me']['get']['responses']['401'];

    expect(accCodes($spec, '/me', 'get'))->toBe(['200', '401'])
        ->and(accCodes($spec, '/plain-auth', 'get'))->toBe(['200', '401'])
        ->and(accCodes($spec, '/by-class', 'get'))->toBe(['200', '401'])
        ->and(accCodes($spec, '/public', 'get'))->toBe(['200'])
        ->and($unauthenticated['description'])->toBe('Unauthenticated: sign in first (problem type https://pokeprices.andrestheperez.com/problems/unauthenticated).')
        ->and($unauthenticated['content'])->toBe(['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]]);
});

it('adds the 419 csrf-token-mismatch problem to non-GET stateful routes and never to GET ones', function (): void {
    $router = accRouter(function (Router $r): void {
        $r->get('v1/things', fn (): null => null)->middleware(EnsureFrontendRequestsAreStateful::class);
        $r->put('v1/things/{key}', fn (): null => null)->middleware(EnsureFrontendRequestsAreStateful::class);
        $r->delete('v1/things/{key}', fn (): null => null)->middleware(EnsureFrontendRequestsAreStateful::class);
        $r->post('v1/plain', fn (): null => null);
    });

    $spec = accTransform($router, [
        accOperation('v1/things'), accOperation('v1/things/{key}', 'put'), accOperation('v1/things/{key}', 'delete'), accOperation('v1/plain', 'post'),
    ]);
    $mismatch = $spec['paths']['/things/{key}']['put']['responses']['419'];

    expect(accCodes($spec, '/things', 'get'))->toBe(['200'])
        ->and(accCodes($spec, '/things/{key}', 'put'))->toBe(['200', '419'])
        ->and(accCodes($spec, '/things/{key}', 'delete'))->toBe(['200', '419'])
        ->and(accCodes($spec, '/plain', 'post'))->toBe(['200'])
        ->and($mismatch['description'])->toBe('The CSRF token is missing or stale: GET /sanctum/csrf-cookie and retry (problem type https://pokeprices.andrestheperez.com/problems/csrf-token-mismatch).')
        ->and($mismatch['content'])->toBe(['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]]);
});

it('adds 401 and 419 together to a non-GET route in an authenticated stateful group', function (): void {
    $router = accRouter(function (Router $r): void {
        $r->middleware([EnsureFrontendRequestsAreStateful::class, 'auth:sanctum'])->group(function (Router $r): void {
            $r->put('v1/watch/{key}', fn (): null => null);
        });
    });

    $spec = accTransform($router, [accOperation('v1/watch/{key}', 'put')]);

    expect(accCodes($spec, '/watch/{key}', 'put'))->toBe(['200', '401', '419']);
});
