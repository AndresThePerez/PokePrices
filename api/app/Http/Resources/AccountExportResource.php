<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /v1/me/export (plan P4-i1 D6): everything PokéPrices keeps about the signed-in person, sent as a download named
 * pokeprices-export.json. The fields are App\Domain\Accounts\AccountExport's, never a secret.
 */
final class AccountExportResource extends JsonResource
{
    public const string FILENAME = 'pokeprices-export.json';

    /**
     * @param  array{format: string, version: int, account: array{email: string, email_verified_at: string|null, two_factor: bool, created_at: string|null}, watchlist: list<array{variant_key: string, added_at: string}>, portfolio: list<array{variant_key: string, quantity: int, added_at: string, updated_at: string}>, sessions: list<array{last_active_at: string, ip_address: string|null, user_agent: string|null}>}  $export
     */
    public function __construct(array $export)
    {
        parent::__construct($export);
    }

    /**
     * @return array{format: string, version: int, account: array{email: string, email_verified_at: string|null, two_factor: bool, created_at: string|null}, watchlist: list<array{variant_key: string, added_at: string}>, portfolio: list<array{variant_key: string, quantity: int, added_at: string, updated_at: string}>, sessions: list<array{last_active_at: string, ip_address: string|null, user_agent: string|null}>}
     */
    public function toArray(Request $request): array
    {
        /** @var array{format: string, version: int, account: array{email: string, email_verified_at: string|null, two_factor: bool, created_at: string|null}, watchlist: list<array{variant_key: string, added_at: string}>, portfolio: list<array{variant_key: string, quantity: int, added_at: string, updated_at: string}>, sessions: list<array{last_active_at: string, ip_address: string|null, user_agent: string|null}>} $export */
        $export = $this->resource;

        return $export;
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->headers->set('Content-Disposition', 'attachment; filename="'.self::FILENAME.'"');
    }
}
