<?php

declare(strict_types=1);

namespace App\Http\Problems;

use App\Domain\ProblemType;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** The demo is switched off (DEMO_ENABLED=false) or has not been seeded: 404 demo-unavailable (plan P4-i1 D4, D6). */
final class DemoUnavailable extends HttpException implements ProblemType
{
    public function __construct()
    {
        parent::__construct(404, 'The demo account is not available.');
    }

    public function slug(): string
    {
        return 'demo-unavailable';
    }

    public function title(): string
    {
        return 'Demo Account Unavailable';
    }

    public function extra(): array
    {
        return [];
    }
}
