<?php

declare(strict_types=1);

use App\OpenApi\ContractDocument;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\InfoObject;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Path;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Routing\Router;

// Reviewer edge cases for T32: idempotence, untouched codes, shared responses, middleware spellings, metadata types.

const P1E_REF = ['$ref' => '#/components/schemas/Problem'];

function p1eRouter(Closure $routes): Router
{
    $router = new Router(new Dispatcher(new Container), new Container);
    $routes($router);

    return $router;
}

function p1eDocument(): OpenApi
{
    return OpenApi::make('3.1.0')->setComponents(new Components)->setInfo(InfoObject::make('PokéPrices')->setVersion('0.1.0'));
}

function p1eOperation(string $path, ?string $operationId = null, string $method = 'get'): Operation
{
    return Operation::make($method)->setPath($path)->setOperationId($operationId ?? 'op'.md5($path))->addResponse(
        Response::make(200)->setDescription('OK')->setContent('application/json', Schema::fromType((new ObjectType)->addProperty('data', new StringType)->setRequired(['data'])))
    );
}

/**
 * @param  list<Operation>  $operations
 * @return array<string, mixed>
 */
function p1eTransform(Router $router, OpenApi $openApi, array $operations): array
{
    foreach ($operations as $operation) {
        $openApi->addPath(Path::make(substr($operation->path, 3))->addOperation($operation));
    }
    (new ContractDocument($router))($openApi);

    return json_decode((string) json_encode($openApi->toArray()), true, 512, JSON_THROW_ON_ERROR);
}

it('is idempotent: a second pass over a transformed document changes nothing and never double wraps a problem+json 4xx', function (): void {
    $router = p1eRouter(function (Router $r): void {
        $r->get('v1/cards', ['uses' => fn (): null => null, 'pokesearch' => true])->middleware(['throttle:search', 'App\Http\Middleware\ConditionalGet']);
    });
    $openApi = p1eDocument();
    $already = Response::make(404)->setDescription('Not found')->setContent(ContractDocument::PROBLEM_JSON, Schema::fromType((new ObjectType)->addProperty('x', new StringType)));
    $openApi->addPath(Path::make('cards')->addOperation(p1eOperation('v1/cards')->addResponse($already)));
    (new ContractDocument($router))($openApi);
    $first = json_encode($openApi->toArray(), JSON_THROW_ON_ERROR);
    (new ContractDocument($router))($openApi);
    $second = json_encode($openApi->toArray(), JSON_THROW_ON_ERROR);
    $spec = json_decode($second, true, 512, JSON_THROW_ON_ERROR);

    expect($second)->toBe($first)
        ->and($spec['paths']['/cards']['get']['responses']['404']['content'])->toBe([ContractDocument::PROBLEM_JSON => ['schema' => P1E_REF]])
        ->and(array_keys($spec['paths']['/cards']['get']['responses']))->toBe([200, 304, 404, 429, 502, 503])
        ->and(array_keys($spec['components']['schemas']))->toBe(['Problem']);
});

it('leaves 2xx, 3xx, default and range-key responses alone and rewrites only numeric 4xx and 5xx', function (): void {
    $router = p1eRouter(fn (Router $r) => $r->get('v1/cards', fn (): null => null));
    $body = fn (): Schema => Schema::fromType((new ObjectType)->addProperty('m', new StringType));
    $operation = p1eOperation('v1/cards')
        ->addResponse(Response::make(201)->setDescription('Created')->setContent('application/json', $body()))
        ->addResponse(Response::make(301)->setDescription('Moved')->setContent('application/json', $body()))
        ->addResponse(Response::make(399)->setDescription('Edge')->setContent('application/json', $body()))
        ->addResponse(Response::make(400)->setDescription('Bad')->setContent('application/json', $body()))
        ->addResponse(Response::make(599)->setDescription('Edge 5xx')->setContent('application/json', $body()))
        ->addResponse(Response::make('default')->setDescription('Fallback')->setContent('application/json', $body()));
    $spec = p1eTransform($router, p1eDocument(), [$operation]);
    $responses = $spec['paths']['/cards']['get']['responses'];

    foreach (['200', '201', '301', '399', 'default'] as $code) {
        expect($responses[$code]['content'])->toHaveKey('application/json')->and($responses[$code]['content'])->not->toHaveKey(ContractDocument::PROBLEM_JSON);
    }
    foreach (['400', '599'] as $code) {
        expect($responses[$code]['content'])->toBe([ContractDocument::PROBLEM_JSON => ['schema' => P1E_REF]]);
    }
    expect(array_keys($responses))->toBe([200, 201, 301, 399, 400, 599, 'default']);
});

it('inlines one shared validation response for several operations, keeps a shared 2xx reference, and survives a dangling reference', function (): void {
    $router = p1eRouter(function (Router $r): void {
        $r->get('v1/cards', fn (): null => null);
        $r->get('v1/sets', fn (): null => null);
    });
    $openApi = p1eDocument();
    $components = $openApi->components;
    $validation = $components->add(
        new Reference('responses', 'Illuminate\Validation\ValidationException', $components),
        Response::make(422)->setDescription('Validation error')->setContent('application/json', Schema::fromType((new ObjectType)->addProperty('message', new StringType)))
    );
    $shared = $components->add(new Reference('responses', 'Shared200', $components), Response::make(200)->setDescription('Shared OK')->setContent('application/json', Schema::fromType(new StringType)));
    $dangling = new Reference('responses', 'Gone', $components);
    $cards = Operation::make('get')->setPath('v1/cards')->setOperationId('listCards')->addResponse($shared)->addResponse($validation)->addResponse($dangling);
    $sets = Operation::make('get')->setPath('v1/sets')->setOperationId('listSets')->addResponse($shared)->addResponse($validation);
    $spec = p1eTransform($router, $openApi, [$cards, $sets]);

    foreach (['/cards', '/sets'] as $path) {
        $responses = $spec['paths'][$path]['get']['responses'];
        expect($responses['422']['content'])->toBe([ContractDocument::PROBLEM_JSON => ['schema' => P1E_REF]])
            ->and($responses['200'])->toBe(['$ref' => '#/components/responses/Shared200']);
    }
    expect(array_keys($spec['components']['responses']))->toBe(['Shared200'])
        ->and(array_keys($spec['paths']['/cards']['get']['responses']))->toBe([200, 422]);
});

it('documents 304 and 429 together on one operation, each with its own header, sorted by code', function (): void {
    $router = p1eRouter(fn (Router $r) => $r->get('v1/cards', fn (): null => null)->middleware(['App\Http\Middleware\ConditionalGet', 'throttle:search']));
    $spec = p1eTransform($router, p1eDocument(), [p1eOperation('v1/cards')]);
    $responses = $spec['paths']['/cards']['get']['responses'];

    expect(array_keys($responses))->toBe([200, 304, 429])
        ->and($responses['304'])->not->toHaveKey('content')
        ->and($responses['304'])->not->toHaveKey('Retry-After')
        ->and(array_keys($responses['304']['headers']))->toBe(['ETag'])
        ->and(array_keys($responses['429']['headers']))->toBe(['Retry-After'])
        ->and($responses['429']['content'])->toBe([ContractDocument::PROBLEM_JSON => ['schema' => P1E_REF]]);
});

it('recognises throttle through an alias with a tier, through a middleware group, and through the Redis subclass', function (): void {
    $router = p1eRouter(function (Router $r): void {
        $r->aliasMiddleware('limit', ThrottleRequests::class);
        $r->aliasMiddleware('redis-limit', ThrottleRequestsWithRedis::class);
        $r->middlewareGroup('api-limited', ['throttle:60,1']);
        $r->get('v1/cards', fn (): null => null)->middleware('limit:search');
        $r->get('v1/sets', fn (): null => null)->middleware('redis-limit:search');
        $r->get('v1/series', fn (): null => null)->middleware('api-limited');
        $r->get('v1/rarities', fn (): null => null)->middleware('auth');
    });
    $spec = p1eTransform($router, p1eDocument(), [
        p1eOperation('v1/cards'), p1eOperation('v1/sets'), p1eOperation('v1/series'), p1eOperation('v1/rarities'),
    ]);

    foreach (['/cards', '/sets', '/series'] as $path) {
        expect($spec['paths'][$path]['get']['responses'])->toHaveKey(429);
    }
    expect(array_keys($spec['paths']['/rarities']['get']['responses']))->toBe([200]);
});

it('adds 503 and 502 only when the pokesearch attribute is exactly boolean true', function (): void {
    $router = p1eRouter(function (Router $r): void {
        $r->get('v1/a', ['uses' => fn (): null => null, 'pokesearch' => false]);
        $r->get('v1/b', ['uses' => fn (): null => null, 'pokesearch' => 'true']);
        $r->get('v1/c', ['uses' => fn (): null => null, 'pokesearch' => 1]);
        $r->get('v1/d', ['uses' => fn (): null => null, 'pokesearch' => null]);
        $r->get('v1/e', ['uses' => fn (): null => null, 'pokesearch' => []]);
        $r->get('v1/f', ['uses' => fn (): null => null, 'pokesearch' => true]);
        $r->get('v1/g', ['uses' => fn (): null => null, 'pokesearch' => 'false']);
    });
    $spec = p1eTransform($router, p1eDocument(), array_map(fn (string $p): Operation => p1eOperation('v1/'.$p), ['a', 'b', 'c', 'd', 'e', 'f', 'g']));

    foreach (['a', 'b', 'c', 'd', 'e', 'g'] as $p) {
        expect(array_keys($spec['paths']['/'.$p]['get']['responses']))->toBe([200]);
    }
    expect(array_keys($spec['paths']['/f']['get']['responses']))->toBe([200, 502, 503]);
});

it('reuses a response Scramble already documented under a route-driven code instead of duplicating it', function (): void {
    $router = p1eRouter(fn (Router $r) => $r->get('v1/cards', fn (): null => null)->middleware(['throttle:search', 'conditional-get']));
    $operation = p1eOperation('v1/cards')
        ->addResponse(Response::make(429)->setDescription('Scramble guess')->setContent('application/json', Schema::fromType(new StringType)))
        ->addResponse(Response::make(304)->setDescription('Scramble guess')->setContent('application/json', Schema::fromType(new StringType)));
    $spec = p1eTransform($router, p1eDocument(), [$operation]);
    $responses = $spec['paths']['/cards']['get']['responses'];

    expect(array_keys($responses))->toBe([200, 304, 429])
        ->and($responses['429']['description'])->toBe('Scramble guess')
        ->and($responses['429']['content'])->toBe([ContractDocument::PROBLEM_JSON => ['schema' => P1E_REF]])
        ->and($responses['429']['headers']['Retry-After']['required'])->toBeTrue()
        ->and($responses['304'])->not->toHaveKey('content')
        ->and($responses['304']['headers']['ETag']['required'])->toBeTrue();
});

it('keeps the getReady 503 body identical to the committed contract and does not let route metadata replace it', function (): void {
    $committed = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/openapi/api.json'), true, 512, JSON_THROW_ON_ERROR);
    $router = p1eRouter(fn (Router $r) => $r->get('v1/ready', ['uses' => fn (): null => null, 'pokesearch' => false])->middleware('throttle:search'));
    $ready = p1eOperation('v1/ready', 'getReady')->addResponse(Response::make(503)->setDescription('Not ready')->setContent(
        ContractDocument::PROBLEM_JSON, Schema::fromType((new ObjectType)->addProperty('status', new IntegerType))
    ));
    $spec = p1eTransform($router, p1eDocument(), [$ready]);
    $mine = $spec['paths']['/ready']['get']['responses']['503']['content'];
    $theirs = $committed['paths']['/ready']['get']['responses']['503']['content'];

    expect(json_encode($mine, JSON_THROW_ON_ERROR))->toBe(json_encode($theirs, JSON_THROW_ON_ERROR))
        ->and($spec['paths']['/ready']['get']['responses'])->toHaveKey(429)
        ->and($committed['components']['schemas'] ?? [])->not->toHaveKey('Problem');
});

it('leaves an operation with no route metadata unrouted yet still rewrites its 4xx to the Problem reference', function (): void {
    $router = p1eRouter(fn (Router $r) => null);
    $operation = p1eOperation('v1/orphan')->addResponse(Response::make(401)->setDescription('No')->setContent('application/json', Schema::fromType(new StringType)));
    $spec = p1eTransform($router, p1eDocument(), [$operation]);

    expect(array_keys($spec['paths']['/orphan']['get']['responses']))->toBe([200, 401])
        ->and($spec['paths']['/orphan']['get']['responses']['401']['content'])->toBe([ContractDocument::PROBLEM_JSON => ['schema' => P1E_REF]])
        ->and($spec['components']['schemas']['Problem']['additionalProperties'])->toBeFalse()
        ->and($spec['components']['schemas']['Problem']['required'])->toContain('request_id')
        ->and($spec['components']['schemas']['Problem']['required'])->not->toContain('detail', 'errors', 'catalog_missing');
});
