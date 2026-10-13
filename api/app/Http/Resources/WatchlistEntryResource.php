<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The answer to PUT /v1/watchlist/{variantKey} (plan P4-i1 D6): the variant key now on the watchlist. */
final class WatchlistEntryResource extends JsonResource
{
    public function __construct(private readonly string $variantKey)
    {
        parent::__construct($variantKey);
    }

    /** @return array{variant_key: string} */
    public function toArray(Request $request): array
    {
        return ['variant_key' => $this->variantKey];
    }
}
