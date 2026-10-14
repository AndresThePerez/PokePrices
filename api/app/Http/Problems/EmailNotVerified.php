<?php

declare(strict_types=1);

namespace App\Http\Problems;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Only a verified account may create alerts or webhooks: 403 email-not-verified (plan P5-i1 D9; P4 D15). */
final class EmailNotVerified extends HttpException
{
    public function __construct()
    {
        parent::__construct(403, 'Verify your email address first.');
    }
}
