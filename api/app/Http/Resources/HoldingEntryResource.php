<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The answer to PUT /v1/portfolio/{variantKey}: the variant key and the quantity now held. */
final class HoldingEntryResource extends JsonResource
{
    public function __construct(private readonly string $variantKey, private readonly int $quantity)
    {
        parent::__construct($variantKey);
    }

    /** @return array{variant_key: string, quantity: int} */
    public function toArray(Request $request): array
    {
        return ['variant_key' => $this->variantKey, 'quantity' => $this->quantity];
    }
}
