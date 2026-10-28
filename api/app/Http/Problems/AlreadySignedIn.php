<?php

declare(strict_types=1);

namespace App\Http\Problems;

use App\Domain\ProblemType;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** A signed-in person posted to a guest-only route (sign in, register, reset, two-factor challenge): 409, never a redirect. */
final class AlreadySignedIn extends HttpException implements ProblemType
{
    public function __construct()
    {
        parent::__construct(409, 'You are already signed in.');
    }

    public function slug(): string
    {
        return 'already-signed-in';
    }

    public function title(): string
    {
        return 'Already Signed In';
    }

    public function extra(): array
    {
        return [];
    }
}
