<?php

declare(strict_types=1);

namespace App\OpenApi;

use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;

/** Scramble document transformer: what the PokéPrices contract states that inference cannot (.spectral.yaml). */
final class ContractDocument
{
    public const string PROBLEM_JSON = 'application/problem+json';

    public function __invoke(OpenApi $openApi): void
    {
        // The contract's only server is the relative /v1, so the export never depends on APP_URL (R78).
        $openApi->servers = [new Server('/v1')];
        $openApi->info = ContractInfo::from($openApi->info, 'PokéPrices');

        foreach ($openApi->paths as $path) {
            foreach ($path->operations as $operation) {
                foreach ($operation->responses ?? [] as $response) {
                    if (! $response instanceof Response) {
                        continue;
                    }
                    if ($operation->operationId === 'getReady' && (string) $response->code === '503') {
                        $response->content = [self::PROBLEM_JSON => Schema::fromType(self::readinessProblem())];
                    }
                    foreach ($response->content as $mediaType => $schema) {
                        if ($schema instanceof Schema) {
                            $schema = Schema::fromType(self::close($schema->type));
                            $response->content[$mediaType] = $schema;
                        }
                    }
                }
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

    /** Every object schema that lists properties becomes closed, nested ones included. */
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
