<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Pricing\PricingConfig;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in account as GET /v1/me answers it: exactly email, email_verified, two_factor (true
 * once two-factor authentication is confirmed), read_only (the demo account) and portfolio, the account's own
 * portfolio figures (Portfolio::summary(), the same figures GET /v1/portfolio's meta carries) with their provenance.
 * Never any other user field.
 */
final class MeResource extends JsonResource
{
    /**
     * @param  array{count: int, priced: int, unpriced: int, no_previous_close: int, total_value: string|null, change: string|null, change_pct: string|null, first_day: string|null, last_day: string|null}  $portfolio
     */
    public function __construct(User $user, private readonly array $portfolio)
    {
        parent::__construct($user);
    }

    /**
     * @return array{email: string, email_verified: bool, two_factor: bool, read_only: bool, portfolio: array{count: int, priced: int, unpriced: int, no_previous_close: int, total_value: string|null, change: string|null, change_pct: string|null, first_day: string|null, last_day: string|null, source: string, feed: string, basis: 'market', currency: 'USD'}}
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            /** @var string */
            'email' => $user->email,
            /** @var bool */
            'email_verified' => $user->hasVerifiedEmail(),
            'two_factor' => $user->two_factor_confirmed_at !== null,
            /** @var bool */
            'read_only' => $user->is_demo,
            'portfolio' => [...$this->portfolio, ...PricingConfig::provenance()],
        ];
    }
}
