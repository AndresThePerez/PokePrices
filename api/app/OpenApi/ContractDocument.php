<?php

declare(strict_types=1);

namespace App\OpenApi;

use App\Http\Problems\ProblemDetails;
use Dedoc\Scramble\Exceptions\OpenApiReferenceTargetNotFoundException;
use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;

/**
 * Scramble document transformer: what the PokéPrices contract states that inference cannot (.spectral.yaml).
 *
 * Every 4xx/5xx answers application/problem+json with the shared Problem schema (getReady's 503 keeps its
 * readiness body). The extra responses of an operation follow its route's metadata, never a hand list:
 * - middleware CONDITIONAL_GET (the class, or the alias CONDITIONAL_GET_ALIAS): 304 with ETag, no content;
 * - middleware `throttle` / `throttle:<tier>` (ThrottleRequests or a subclass): 429 with Retry-After;
 * - route action attribute POKESEARCH === true, e.g. Route::group(['pokesearch' => true], ...); the innermost
 *   declaration wins: 503 upstream-unavailable with Retry-After and 502 upstream-contract;
 * - middleware resolving to Authenticate or a subclass (`auth`, `auth:<guard>`): 401 unauthenticated (plan P4-i1 D4);
 * - middleware STATEFUL (Sanctum's EnsureFrontendRequestsAreStateful) on an operation whose method is not GET or
 *   HEAD: 419 csrf-token-mismatch (plan P4-i1 D4);
 * - middleware DEMO_READ_ONLY (App\Http\Middleware\DemoReadOnly) on an operation whose method is not GET or HEAD:
 *   403 demo-read-only (plan P4-i1 D4).
 */
final class ContractDocument
{
    public const string PROBLEM_JSON = 'application/problem+json';

    public const string CONDITIONAL_GET = 'App\Http\Middleware\ConditionalGet';

    public const string CONDITIONAL_GET_ALIAS = 'conditional-get';

    public const string THROTTLE_ALIAS = 'throttle';

    public const string POKESEARCH = 'pokesearch';

    public const string STATEFUL = 'Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful';

    public const string DEMO_READ_ONLY = 'App\Http\Middleware\DemoReadOnly';

    /** @param Router|null $router the routes to read; null means the application's, aliases and groups registered */
    public function __construct(private readonly ?Router $router = null) {}

    public function __invoke(OpenApi $openApi): void
    {
        // The contract's only server is the relative /v1, so the export never depends on APP_URL (R78).
        $openApi->servers = [new Server('/v1')];
        $openApi->info = ContractInfo::from($openApi->info, 'PokéPrices');

        $router = $this->router ?? self::applicationRouter();
        $routes = self::routeIndex($router);
        $inlined = [];

        foreach ($openApi->paths as $path) {
            foreach ($path->operations as $operation) {
                $responses = [];
                foreach ($operation->responses ?? [] as $response) {
                    $responses[] = self::inlineError($response, $inlined);
                }
                $route = $routes[self::routeKey($operation->method, $operation->path)] ?? null;
                if ($route instanceof Route) {
                    self::addRouteResponses($responses, $router, $route, $operation->method);
                }
                foreach ($responses as $response) {
                    if (! $response instanceof Response) {
                        continue;
                    }
                    if ($operation->operationId === 'getReady' && (string) $response->code === '503') {
                        $response->content = [self::PROBLEM_JSON => Schema::fromType(self::readinessProblem())];
                    } elseif (self::isError($response->code)) {
                        $response->content = [self::PROBLEM_JSON => Schema::fromType(ProblemSchema::reference($openApi->components))];
                    }
                    foreach ($response->content as $mediaType => $schema) {
                        if ($schema instanceof Schema) {
                            $schema = Schema::fromType(self::close($schema->type));
                            $response->content[$mediaType] = $schema;
                        }
                    }
                }
                usort($responses, fn (Response|Reference $a, Response|Reference $b): int => strcmp(self::code($a), self::code($b)));
                $operation->responses = $responses;
                // Request bodies close the same way (a PUT's validated fields, plan P4-i1 T05).
                foreach ($operation->requestBodyObject->content ?? [] as $mediaType => $schema) {
                    if ($schema instanceof Schema && $operation->requestBodyObject !== null) {
                        $operation->requestBodyObject->content[$mediaType] = Schema::fromType(self::close($schema->type));
                    }
                }
            }
        }

        // Component schemas (a JsonResource such as CardSummaryResource) close the same way as inline bodies.
        foreach ($openApi->components->schemas as $schema) {
            if ($schema instanceof Schema) {
                $schema->type = self::close($schema->type);
            }
        }

        self::dropUnusedResponses($openApi, array_keys($inlined));
    }

    /** scramble:export never builds the HTTP kernel, so resolve it first: it registers the middleware aliases and groups on the router. */
    private static function applicationRouter(): Router
    {
        app()->make(Kernel::class);

        return app()->make(Router::class);
    }

    /** @return array<string, Route> every route under "METHOD uri", path parameter names erased */
    private static function routeIndex(Router $router): array
    {
        $index = [];
        foreach ($router->getRoutes()->getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $index[self::routeKey($method, $route->uri())] ??= $route;
            }
        }

        return $index;
    }

    /** Scramble renames path parameters and drops the optional marker, so the key keeps only their positions. */
    private static function routeKey(string $method, string $uri): string
    {
        return strtoupper($method).' '.preg_replace('/\{[^}]*\}/', '{}', trim($uri, '/'));
    }

    /**
     * Scramble refers 4xx bodies such as a failed validation to shared application/json responses; inline them.
     *
     * @param  array<string, true>  $inlined  the shared responses no longer referred to from here
     */
    private static function inlineError(Response|Reference $response, array &$inlined): Response|Reference
    {
        if (! $response instanceof Reference) {
            return $response;
        }
        try {
            $target = $response->resolve();
        } catch (OpenApiReferenceTargetNotFoundException) {
            return $response;
        }
        if (! $target instanceof Response || ! self::isError($target->code)) {
            return $response;
        }
        $inlined[$response->fullName] = true;

        return (new Response($target->code))->setDescription($target->description)->setHeaders($target->headers);
    }

    /**
     * @param  list<Response|Reference>  $responses
     *
     * @param-out list<Response|Reference> $responses
     */
    private static function addRouteResponses(array &$responses, Router $router, Route $route, ?string $method): void
    {
        // Router::gatherRouteMiddleware without its priority sort, which probes every name as a class.
        $resolve = fn (array $names): array => Arr::flatten(array_map(
            fn (mixed $name): mixed => is_string($name) ? MiddlewareNameResolver::resolve($name, $router->getMiddleware(), $router->getMiddlewareGroups()) : $name,
            $names,
        ));
        $excluded = $resolve($route->excludedMiddleware());
        $middleware = [];
        foreach ($resolve($route->gatherMiddleware()) as $name) {
            if (is_string($name) && ! in_array($name, $excluded, true)) {
                $middleware[] = ltrim(explode(':', $name, 2)[0], '\\');
            }
        }

        if (in_array(self::CONDITIONAL_GET, $middleware, true) || in_array(self::CONDITIONAL_GET_ALIAS, $middleware, true)) {
            $notModified = self::response($responses, 304, 'Not Modified: the If-None-Match validator matches the current representation.');
            $notModified->content = [];
            $notModified->addHeader('ETag', self::header('The validator of the current representation.', new StringType));
        }

        $throttled = array_filter($middleware, fn (string $name): bool => $name === self::THROTTLE_ALIAS || is_a($name, ThrottleRequests::class, true));
        if ($throttled !== []) {
            self::response($responses, 429, 'Too Many Requests: the rate limit of this route is spent.')
                ->addHeader('Retry-After', self::header('Seconds until the limit resets.', new IntegerType));
        }

        $pokeSearch = $route->getAction(self::POKESEARCH);
        if (Arr::last(Arr::flatten(is_array($pokeSearch) ? $pokeSearch : [$pokeSearch])) === true) {
            self::response($responses, 503, 'PokéSearch did not answer (problem type '.ProblemDetails::BASE.'upstream-unavailable).')
                ->addHeader('Retry-After', self::header('Seconds before PokéSearch is worth asking again.', new IntegerType));
            self::response($responses, 502, 'PokéSearch answered outside its contract (problem type '.ProblemDetails::BASE.'upstream-contract).');
        }

        // By resolution only: `auth` / `auth:<guard>` count once the router aliases them to Authenticate.
        $authenticated = array_filter($middleware, fn (string $name): bool => is_a($name, Authenticate::class, true));
        if ($authenticated !== []) {
            // Scramble may already document a bare 401 (it reads the auth middleware); the contract's wording wins.
            $description = 'Unauthenticated: sign in first (problem type '.ProblemDetails::BASE.'unauthenticated).';
            self::response($responses, 401, $description)->setDescription($description);
        }

        $writes = ! in_array(strtoupper((string) $method), ['GET', 'HEAD'], true);
        if ($writes && array_filter($middleware, fn (string $name): bool => is_a($name, self::STATEFUL, true)) !== []) {
            self::response($responses, 419, 'The CSRF token is missing or stale: GET /sanctum/csrf-cookie and retry (problem type '.ProblemDetails::BASE.'csrf-token-mismatch).');
        }
        if ($writes && array_filter($middleware, fn (string $name): bool => is_a($name, self::DEMO_READ_ONLY, true)) !== []) {
            $description = 'The demo account is read-only (problem type '.ProblemDetails::BASE.'demo-read-only).';
            self::response($responses, 403, $description)->setDescription($description);
        }
    }

    /**
     * The operation's response with this code, added with the description when Scramble documented none.
     *
     * @param  list<Response|Reference>  $responses
     *
     * @param-out list<Response|Reference> $responses
     */
    private static function response(array &$responses, int $code, string $description): Response
    {
        foreach ($responses as $response) {
            if ($response instanceof Response && (string) $response->code === (string) $code) {
                return $response;
            }
        }
        $response = (new Response($code))->setDescription($description);
        $responses[] = $response;

        return $response;
    }

    private static function header(string $description, Type $type): Header
    {
        return new Header(description: $description, required: true, schema: Schema::fromType($type));
    }

    private static function isError(int|string|null $code): bool
    {
        return is_numeric($code) && (int) $code >= 400;
    }

    private static function code(Response|Reference $response): string
    {
        if ($response instanceof Reference) {
            try {
                $response = $response->resolve();
            } catch (OpenApiReferenceTargetNotFoundException) {
                return 'default';
            }
        }

        return $response instanceof Response && $response->code !== null ? (string) $response->code : 'default';
    }

    /** @param list<string> $inlined */
    private static function dropUnusedResponses(OpenApi $openApi, array $inlined): void
    {
        $used = [];
        foreach ($openApi->paths as $path) {
            foreach ($path->operations as $operation) {
                foreach ($operation->responses ?? [] as $response) {
                    if ($response instanceof Reference && $response->referenceType === 'responses') {
                        $used[$response->fullName] = true;
                    }
                }
            }
        }
        foreach ($inlined as $name) {
            if (! isset($used[$name])) {
                $openApi->components->removeResponse($name);
            }
        }
    }

    /** The RFC 9457 body ReadinessController answers on a failed check (ProblemDetails::response plus checks). */
    private static function readinessProblem(): ObjectType
    {
        $checks = (new ObjectType)->additionalProperties((new StringType)->enum(['ok', 'fail']));

        return (new ObjectType)
            ->addProperty('type', new StringType)
            ->addProperty('title', new StringType)
            ->addProperty('status', new IntegerType)
            ->addProperty('instance', new StringType)
            ->addProperty('request_id', new StringType)
            ->addProperty('detail', new StringType)
            ->addProperty('checks', $checks)
            ->setRequired(['type', 'title', 'status', 'instance', 'request_id', 'detail', 'checks']);
    }

    /** Every object schema that lists properties becomes closed, nested ones included (response and request bodies). */
    private static function close(Type $type): Type
    {
        if ($type instanceof ObjectType) {
            foreach ($type->properties as $name => $property) {
                if ($property instanceof Type) {
                    $type->properties[$name] = self::close($property);
                }
            }
            if ($type->additionalProperties instanceof Type) {
                $type->additionalProperties = self::close($type->additionalProperties);
            }
            if ($type->properties !== [] && ! $type instanceof ClosedObjectType) {
                return ClosedObjectType::from($type);
            }
        }
        if ($type instanceof ArrayType && $type->items instanceof Type) {
            $type->items = self::close($type->items);
        }

        return $type;
    }
}
