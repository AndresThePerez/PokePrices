<?php

declare(strict_types=1);

namespace App\OpenApi;

use App\Http\Problems\ProblemDetails;
use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

/** The RFC 9457 body every PokéPrices error answers with (ProblemDetails::response), as the shared Problem component. */
final class ProblemSchema
{
    public const string NAME = 'Problem';

    public static function type(): ClosedObjectType
    {
        $errors = (new ObjectType)->additionalProperties((new ArrayType)->setItems(new StringType));
        $errors->setDescription('Validation messages per input field (422 only).');
        $catalogMissing = new BooleanType;
        $catalogMissing->setDescription('card-not-found only: true when PokéSearch no longer has the card but PokéPrices still holds variant rows for it.');

        $problem = (new ObjectType)
            ->addProperty('type', (new StringType)->format('uri'))
            ->addProperty('title', new StringType)
            ->addProperty('status', new IntegerType)
            ->addProperty('instance', new StringType)
            ->addProperty('request_id', new StringType)
            ->addProperty('detail', new StringType)
            ->addProperty('errors', $errors)
            ->addProperty('catalog_missing', $catalogMissing)
            ->setRequired(['type', 'title', 'status', 'instance', 'request_id']);
        $problem->setDescription('RFC 9457 problem details; type is '.ProblemDetails::BASE.'<slug>.');

        return ClosedObjectType::from($problem);
    }

    /** A reference to the Problem component, which is registered on first use so the document never carries it unused. */
    public static function reference(Components $components): Reference
    {
        if (! $components->hasSchema(self::NAME)) {
            return $components->addSchema(self::NAME, Schema::fromType(self::type()));
        }

        return $components->getSchemaReference(self::NAME);
    }
}
