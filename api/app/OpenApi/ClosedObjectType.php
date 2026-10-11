<?php

declare(strict_types=1);

namespace App\OpenApi;

use Dedoc\Scramble\Support\Generator\Types\ObjectType;

/** An object schema that admits no properties beyond the ones it lists (additionalProperties: false). */
final class ClosedObjectType extends ObjectType
{
    public static function from(ObjectType $source): self
    {
        $closed = new self;
        $closed->properties = $source->properties;
        $closed->required = $source->required;
        $closed->description = $source->description;
        $closed->nullable = $source->nullable;

        return $closed;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $result = parent::toArray();
        unset($result['additionalProperties']);
        $result['additionalProperties'] = false;

        return $result;
    }
}
