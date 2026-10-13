<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\MeResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group('Account', 'The signed-in PokéPrices account (cookie session; sign in through the session endpoints first).')]
final class MeController
{
    /**
     * Who is signed in.
     *
     * email is the account's lower-cased email address; email_verified says whether it has been verified; two_factor
     * is true once two-factor authentication is confirmed; read_only is true for the read-only demo account. Signed
     * out, the answer is 401. The answer is never cached.
     *
     * @operationId getMe
     */
    public function __invoke(Request $request): MeResource
    {
        // auth:sanctum on the account group has already answered 401 to a signed-out request.
        /** @var User $user */
        $user = $request->user();

        return new MeResource($user);
    }
}
