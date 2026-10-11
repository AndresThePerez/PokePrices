<?php

declare(strict_types=1);

namespace Tests\Support;

use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\PathFinder;
use League\OpenAPIValidation\PSR7\ResponseValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates a Symfony response against one operation of the PokéPrices OpenAPI contract (C3).
 */
final class ContractValidator
{
    /** @var array<string, self> */
    private static array $instances = [];

    private function __construct(
        private readonly ResponseValidator $validator,
        private readonly PsrHttpFactory $psrFactory,
    ) {}

    public static function forSpec(string $path): self
    {
        if (! isset(self::$instances[$path])) {
            $psr17 = new Psr17Factory;
            self::$instances[$path] = new self(
                (new ValidatorBuilder)->fromJsonFile($path)->getResponseValidator(),
                new PsrHttpFactory($psr17, $psr17, $psr17, $psr17),
            );
        }

        return self::$instances[$path];
    }

    /**
     * @return array{operation: string|null, validated: bool, reason: string}
     */
    public function validate(string $method, string $uriPath, Response $response): array
    {
        $found = (new PathFinder($this->validator->getSchema(), $uriPath, strtolower($method)))->search();

        if ($found === []) {
            $contentType = (string) $response->headers->get('Content-Type', '');
            $problem = in_array($response->getStatusCode(), [404, 405], true)
                && str_starts_with(strtolower($contentType), 'application/problem+json');

            return [
                'operation' => null,
                'validated' => $problem,
                'reason' => $problem ? 'unmatched_path_problem' : 'no_operation',
            ];
        }

        $specPath = $found[0]->path();
        $operation = strtolower($method).' '.$specPath;

        try {
            $this->validator->validate(
                new OperationAddress($specPath, strtolower($method)),
                $this->psrFactory->createResponse($response),
            );
        } catch (ValidationFailed $failed) {
            return [
                'operation' => $operation,
                'validated' => false,
                'reason' => (new \ReflectionClass($failed))->getShortName(),
            ];
        }

        return ['operation' => $operation, 'validated' => true, 'reason' => 'ok'];
    }
}
