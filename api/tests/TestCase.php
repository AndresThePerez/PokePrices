<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ContractValidator;

abstract class TestCase extends BaseTestCase
{
    public static function ensureSafeTestDatabase(string $connection, mixed $database): void
    {
        $name = is_string($database) ? $database : '';

        if ($connection !== 'pgsql' || ! str_starts_with($name, 'pokeprices_test')) {
            throw new RuntimeException(
                sprintf('PokéPrices tests refuse to run against database %s; expected pokeprices_test*', $name),
            );
        }
    }

    /**
     * @return array<class-string, class-string>
     */
    protected function setUpTraits(): array
    {
        $default = config('database.default');

        self::ensureSafeTestDatabase(
            is_string($default) ? $default : '',
            config('database.connections.pgsql.database'),
        );

        return parent::setUpTraits();
    }

    /**
     * Every /v1 response a feature test receives is validated against openapi/api.json (C3).
     *
     * @param  Response  $response
     * @param  Request|null  $request
     * @return TestResponse<Response>
     */
    protected function createTestResponse(mixed $response, mixed $request): TestResponse
    {
        $testResponse = parent::createTestResponse($response, $request);
        if ($request === null) {
            return $testResponse;
        }
        $path = '/'.ltrim($request->path(), '/');
        if (! str_starts_with($path, '/v1/')) {
            return $testResponse;
        }
        // /v1/__* routes exist only inside tests (the deliberate 500 in ProblemJsonTest); logged, never counted.
        // HEAD carries no body and the contract documents no HEAD operation (R81); logged, never counted.
        $result = match (true) {
            str_starts_with($path, '/v1/__') => ['operation' => null, 'validated' => true, 'reason' => 'test_route'],
            $request->getMethod() === 'HEAD' => ['operation' => null, 'validated' => true, 'reason' => 'head_request'],
            default => ContractValidator::forSpec(base_path('../openapi/api.json'))->validate($request->getMethod(), $path, $response),
        };
        $ledger = getenv('PP_C3_LEDGER');
        if (is_string($ledger) && $ledger !== '') {
            file_put_contents($ledger, json_encode([
                'method' => $request->getMethod(), 'path' => $path, 'status' => $response->getStatusCode(),
                'operation' => $result['operation'], 'validated' => $result['validated'], 'reason' => $result['reason'],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
        }
        if (! $result['validated']) {
            throw new AssertionFailedError("Response to {$request->getMethod()} {$path} does not match openapi/api.json: {$result['reason']}");
        }

        return $testResponse;
    }
}
