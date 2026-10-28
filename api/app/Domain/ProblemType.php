<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * An RFC 9457 problem a PokéPrices exception answers with: ProblemDetails renders its slug (appended to
 * ProblemDetails::BASE as the type), its title, the exception's status code and any extra members it carries.
 */
interface ProblemType
{
    /** The slug after ProblemDetails::BASE, e.g. card-not-found. */
    public function slug(): string;

    /** The problem's human title, e.g. Card Not Found. */
    public function title(): string;

    /** @return array<string, mixed> extra members after the standard ones, e.g. ['catalog_missing' => true] */
    public function extra(): array;
}
