<?php

declare(strict_types=1);

namespace App\Http\Problems;

use App\Domain\ProblemType;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Only a verified account may create alerts or webhooks: 403 email-not-verified. */
final class EmailNotVerified extends HttpException implements ProblemType
{
    public function __construct()
    {
        parent::__construct(403, 'Verify your email address first.');
    }

    public function slug(): string
    {
        return 'email-not-verified';
    }

    public function title(): string
    {
        return 'Verify Your Email First';
    }

    public function extra(): array
    {
        return [];
    }
}
