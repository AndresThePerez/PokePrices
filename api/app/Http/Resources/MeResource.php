<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in account as GET /v1/me answers it (plan P4-i1 D6): exactly email, email_verified, two_factor (true
 * once two-factor authentication is confirmed) and read_only (the demo account). Never any other user field.
 */
final class MeResource extends JsonResource
{
    public function __construct(User $user)
    {
        parent::__construct($user);
    }

    /**
     * @return array{email: string, email_verified: bool, two_factor: bool, read_only: bool}
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
        ];
    }
}
