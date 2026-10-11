<?php

declare(strict_types=1);

namespace App\OpenApi;

use Dedoc\Scramble\Support\Generator\InfoObject;

/** Scramble's info object plus the contact the contract names (Scramble 0.13 has no contact field). */
final class ContractInfo extends InfoObject
{
    public static function from(InfoObject $source, string $contactName): self
    {
        $info = new self($source->title, $source->version);
        $info->description = $source->description;
        $info->contactName = $contactName;

        return $info;
    }

    public string $contactName = '';

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return parent::toArray() + ['contact' => ['name' => $this->contactName]];
    }
}
