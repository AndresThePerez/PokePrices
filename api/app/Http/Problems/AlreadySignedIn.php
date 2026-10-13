<?php

declare(strict_types=1);

namespace App\Http\Problems;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** A signed-in person posted to a guest-only route (sign in, register, reset, two-factor challenge): 409, never a redirect (plan P4-i1 D1, D4). */
final class AlreadySignedIn extends HttpException
{
    public function __construct()
    {
        parent::__construct(409, 'You are already signed in.');
    }
}
