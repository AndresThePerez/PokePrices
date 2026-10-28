<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The answer to PUT /v1/alerts/{variantKey}: the watchlist variant and its alert threshold. */
final class AlertResource extends JsonResource
{
    public function __construct(private readonly string $variantKey, private readonly int $pct)
    {
        parent::__construct($variantKey);
    }

    /** @return array{variant_key: string, alert_pct: int} */
    public function toArray(Request $request): array
    {
        return [
            /** @var string */
            'variant_key' => $this->variantKey,
            /** @var int */
            'alert_pct' => $this->pct,
        ];
    }
}
