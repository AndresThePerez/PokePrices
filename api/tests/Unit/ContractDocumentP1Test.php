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
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;

// Synthetic Scramble documents and synthetic routes drive the transformer (plan T32 criterion 5).

const P1_PROBLEM_REF = ['$ref' => '#/components/schemas/Problem'];

/** A router of its own, so the routes and middleware aliases exist only for the test. */
function p1Router(Closure $routes): Router
{
    $router = new Router(new Dispatcher(new Container), new Container);
    $routes($router);

    return $router;
}

function p1Ok(): Response
{
    return Response::make(200)->setDescription('OK')->setContent('application/json', Schema::fromType(
        (new ObjectType)->addProperty('data', new StringType)->setRequired(['data'])
    ));
}

/** What Scramble 0.13 infers for a failed validation: a reference to a shared application/json response. */
function p1ValidationReference(Components $components): Reference
{
    $errors = (new ObjectType)->additionalProperties((new ArrayType)->setItems(new StringType));
    $body = (new ObjectType)->addProperty('message', new StringType)->addProperty('errors', $errors)->setRequired(['message', 'errors']);
    $response = Response::make(422)->setDescription('Validation error')->setContent('application/json', Schema::fromType($body));

    return $components->add(new Reference('responses', 'Illuminate\Validation\ValidationException', $components), $response);
}

/**
 * @param  list<Operation>  $operations
 * @return array<string, mixed>
 */
function p1Transform(Router $router, OpenApi $openApi, array $operations): array
{
    foreach ($operations as $operation) {
        $openApi->addPath(Path::make(substr($operation->path, 3))->addOperation($operation));
    }
    (new ContractDocument($router))($openApi);

    return json_decode((string) json_encode($openApi->toArray()), true, 512, JSON_THROW_ON_ERROR);
}

function p1Document(): OpenApi
{
    return OpenApi::make('3.1.0')->setComponents(new Components)->setInfo(InfoObject::make('PokéPrices')->setVersion('0.1.0'));
}

function p1Operation(string $path, ?string $operationId = null, string $method = 'get'): Operation
{
    return Operation::make($method)->setPath($path)->setOperationId($operationId ?? 'op'.md5($path))->addResponse(p1Ok());
}

/**
 * @param  array<string, mixed>  $spec
 * @return array<string, mixed>
 */
function p1Responses(array $spec, string $path, string $method = 'get'): array
{
    return $spec['paths'][$path][$method]['responses'];
}

it('defines a closed Problem schema with the five required members and the optional detail, errors and catalog_missing', function (): void {
    $router = p1Router(fn (Router $r) => $r->get('v1/cards', fn (): null => null));
    $openApi = p1Document();
    $spec = p1Transform($router, $openApi, [p1Operation('v1/cards')->addResponse(p1ValidationReference($openApi->components))]);

    expect($spec)->toHaveKey('components.schemas.Problem');
    $problem = $spec['components']['schemas']['Problem'];
    expect(array_keys($problem['properties']))->toBe(['type', 'title', 'status', 'instance', 'request_id', 'detail', 'errors', 'catalog_missing'])
        ->and($problem['type'])->toBe('object')
        ->and($problem['required'])->toBe(['type', 'title', 'status', 'instance', 'request_id'])
        ->and($problem['additionalProperties'])->toBeFalse()
        ->and($problem['properties']['type'])->toMatchArray(['type' => 'string', 'format' => 'uri'])
        ->and($problem['properties']['title']['type'])->toBe('string')
        ->and($problem['properties']['status']['type'])->toBe('integer')
        ->and($problem['properties']['instance']['type'])->toBe('string')
        ->and($problem['properties']['request_id']['type'])->toBe('string')
        ->and($problem['properties']['detail']['type'])->toBe('string')
        ->and($problem['properties']['errors'])->toMatchArray(['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]])
        ->and($problem['properties']['catalog_missing']['type'])->toBe('boolean');
});

it('rewrites every 4xx and 5xx, inline or Scramble-referenced application/json, to problem+json referencing Problem', function (): void {
    $router = p1Router(fn (Router $r) => $r->get('v1/cards', fn (): null => null));
    $openApi = p1Document();
    $notFound = Response::make(404)->setDescription('Not found')->setContent('application/json', Schema::fromType(
        (new ObjectType)->addProperty('message', new StringType)
    ));
    $serverError = Response::make(500)->setDescription('Server error');
    $operation = p1Operation('v1/cards')->addResponse($notFound)->addResponse(p1ValidationReference($openApi->components))->addResponse($serverError);
    $spec = p1Transform($router, $openApi, [$operation]);
    $responses = p1Responses($spec, '/cards');

    foreach (['404', '422', '500'] as $code) {
        expect($responses[$code]['content'])->toBe([ContractDocument::PROBLEM_JSON => ['schema' => P1_PROBLEM_REF]]);
    }
    expect($responses['422']['description'])->toBe('Validation error')
        ->and($responses['200']['content'])->toHaveKey('application/json')
        ->and($spec['components'])->not->toHaveKey('responses');
});

it('documents 304 with an ETag header and no content only behind the conditional-GET middleware', function (): void {
    $router = p1Router(function (Router $r): void {
        $r->aliasMiddleware('etag', 'App\Http\Middleware\ConditionalGet');
        $r->get('v1/cards/{card}', fn (): null => null)->middleware('App\Http\Middleware\ConditionalGet');
        $r->get('v1/sets/{set}', fn (): null => null)->middleware('etag');
        $r->get('v1/series', fn (): null => null)->middleware('conditional-get');
        $r->get('v1/cards', fn (): null => null)->middleware('throttle:search');
    });
    $spec = p1Transform($router, p1Document(), [
        p1Operation('v1/cards/{id}'), p1Operation('v1/sets/{set}'), p1Operation('v1/series'), p1Operation('v1/cards'),
    ]);

    foreach (['/cards/{id}', '/sets/{set}', '/series'] as $path) {
        expect(p1Responses($spec, $path))->toHaveKey(304);
        $notModified = p1Responses($spec, $path)['304'];
        expect($notModified)->not->toHaveKey('content')
            ->and($notModified['headers']['ETag'])->toMatchArray(['required' => true, 'schema' => ['type' => 'string']]);
    }
    expect(p1Responses($spec, '/cards'))->not->toHaveKey('304');
});

it('documents 429 with a Retry-After header only behind throttle', function (): void {
    $router = p1Router(function (Router $r): void {
        $r->get('v1/cards', fn (): null => null)->middleware('throttle:search');
        $r->get('v1/sets', fn (): null => null)->middleware(ThrottleRequests::class.':60,1');
        $r->get('v1/series', fn (): null => null)->middleware('App\Http\Middleware\ConditionalGet');
        $r->get('v1/rarities', fn (): null => null)->middleware('throttle:search')->withoutMiddleware('throttle:search');
    });
    $spec = p1Transform($router, p1Document(), [
        p1Operation('v1/cards'), p1Operation('v1/sets'), p1Operation('v1/series'), p1Operation('v1/rarities'),
    ]);

    foreach (['/cards', '/sets'] as $path) {
        expect(p1Responses($spec, $path))->toHaveKey(429);
        $tooMany = p1Responses($spec, $path)['429'];
        expect($tooMany['content'])->toBe([ContractDocument::PROBLEM_JSON => ['schema' => P1_PROBLEM_REF]])
            ->and($tooMany['headers']['Retry-After'])->toMatchArray(['required' => true, 'schema' => ['type' => 'integer']]);
    }
    expect(p1Responses($spec, '/series'))->not->toHaveKey('429')
        ->and(p1Responses($spec, '/rarities'))->not->toHaveKey('429');
});

it('documents 503 with Retry-After on every rate-limited route (http-503) and every PokéSearch-backed one (upstream-unavailable), 502 upstream-contract only on the latter, and neither on a route that is neither', function (): void {
    $router = p1Router(function (Router $r): void {
        $r->group(['pokesearch' => true], function (Router $r): void {
            $r->get('v1/cards', fn (): null => null)->middleware('throttle:search');
        });
        $r->get('v1/cards/{card}', ['uses' => fn (): null => null, 'pokesearch' => true]);
        $r->get('v1/sets', fn (): null => null)->middleware(['throttle:search', 'App\Http\Middleware\ConditionalGet']);
        // Nested groups merge the attribute into a list; the innermost declaration wins.
        $r->group(['pokesearch' => true], function (Router $r): void {
            $r->group(['pokesearch' => true], fn (Router $r) => $r->get('v1/sets/{set}', fn (): null => null));
            $r->get('v1/series', ['uses' => fn (): null => null, 'pokesearch' => false]);
        });
    });
    $spec = p1Transform($router, p1Document(), [
        p1Operation('v1/cards'), p1Operation('v1/cards/{id}'), p1Operation('v1/sets'), p1Operation('v1/sets/{set}'), p1Operation('v1/series'),
    ]);

    foreach (['/cards', '/cards/{id}', '/sets/{set}'] as $path) {
        $responses = p1Responses($spec, $path);
        expect($responses)->toHaveKeys([502, 503])
            ->and($responses['503']['content'])->toBe([ContractDocument::PROBLEM_JSON => ['schema' => P1_PROBLEM_REF]])
            ->and($responses['503']['headers']['Retry-After'])->toMatchArray(['required' => true, 'schema' => ['type' => 'integer']])
            ->and($responses['503']['description'])->toContain('https://pokeprices.andrestheperez.com/problems/upstream-unavailable')
            ->and($responses['502']['content'])->toBe([ContractDocument::PROBLEM_JSON => ['schema' => P1_PROBLEM_REF]])
            ->and($responses['502']['description'])->toContain('https://pokeprices.andrestheperez.com/problems/upstream-contract');
    }
    // Rate-limited: /cards and /sets. PokéSearch-backed: /cards, /cards/{id} and /sets/{set}. Neither: /series.
    $storeOutage = 'https://pokeprices.andrestheperez.com/problems/http-503';
    foreach (['/cards', '/sets'] as $path) {
        $unavailable = p1Responses($spec, $path)['503'];
        expect($unavailable['description'])->toContain($storeOutage)
            ->and($unavailable['content'])->toBe([ContractDocument::PROBLEM_JSON => ['schema' => P1_PROBLEM_REF]])
            ->and($unavailable['headers']['Retry-After'])->toMatchArray(['required' => true, 'schema' => ['type' => 'integer']]);
    }
    foreach (['/cards/{id}', '/sets/{set}'] as $path) {
        expect(p1Responses($spec, $path)['503']['description'])->not->toContain($storeOutage);
    }
    expect(p1Responses($spec, '/sets')['503']['description'])->not->toContain('https://pokeprices.andrestheperez.com/problems/upstream-unavailable')
        ->and(array_keys(p1Responses($spec, '/sets')))->toBe([200, 304, 429, 503])
        ->and(array_keys(p1Responses($spec, '/cards/{id}')))->toBe([200, 502, 503])
        ->and(array_keys(p1Responses($spec, '/sets/{set}')))->toBe([200, 502, 503])
        ->and(array_keys(p1Responses($spec, '/series')))->toBe([200])
        ->and(array_keys(p1Responses($spec, '/cards')))->toBe([200, 429, 502, 503]);
});

it('adds nothing, not even the Problem component, where no route asks for it, and both where one does', function (): void {
    $router = p1Router(function (Router $r): void {
        $r->post('v1/cards', fn (): null => null)->middleware(['throttle:search', 'App\Http\Middleware\ConditionalGet']);
        $r->get('v1/sets', fn (): null => null)->middleware('throttle:search');
    });
    $unrouted = p1Transform($router, p1Document(), [p1Operation('v1/cards'), p1Operation('v1/elsewhere')]);
    $routed = p1Transform($router, p1Document(), [p1Operation('v1/sets')]);

    expect(array_keys(p1Responses($unrouted, '/cards')))->toBe([200])
        ->and(array_keys(p1Responses($unrouted, '/elsewhere')))->toBe([200])
        ->and($unrouted)->not->toHaveKey('components')
        ->and(array_keys(p1Responses($routed, '/sets')))->toBe([200, 429, 503])
        ->and(array_keys($routed['components']['schemas'] ?? []))->toBe(['Problem']);
});

it('keeps the getReady readiness problem body while other operations reference Problem', function (): void {
    $router = p1Router(function (Router $r): void {
        $r->get('v1/ready', fn (): null => null);
        $r->get('v1/cards', fn (): null => null);
    });
    $ready = p1Operation('v1/ready', 'getReady')->addResponse(Response::make(503)->setDescription('Not ready')->setContent(
        ContractDocument::PROBLEM_JSON, Schema::fromType((new ObjectType)->addProperty('status', new IntegerType))
    ));
    $cards = p1Operation('v1/cards')->addResponse(Response::make(404)->setDescription('Not found'));
    $spec = p1Transform($router, p1Document(), [$ready, $cards]);
    $body = p1Responses($spec, '/ready')['503']['content'][ContractDocument::PROBLEM_JSON]['schema'];

    expect($body)->not->toHaveKey('$ref')
        ->and($body['properties'])->toHaveKey('checks')
        ->and($body['required'])->toContain('request_id', 'checks')
        ->and($body['additionalProperties'])->toBeFalse()
        ->and(p1Responses($spec, '/cards')['404']['content'] ?? null)->toBe([ContractDocument::PROBLEM_JSON => ['schema' => P1_PROBLEM_REF]]);
});
