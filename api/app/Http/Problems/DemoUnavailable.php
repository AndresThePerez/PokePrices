<?php

declare(strict_types=1);

namespace App\Http\Problems;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** The demo is switched off (DEMO_ENABLED=false) or has not been seeded: 404 demo-unavailable (plan P4-i1 D4, D6). */
final class DemoUnavailable extends HttpException
{
    public function __construct()
    {
        parent::__construct(404, 'The demo account is not available.');
    }
}
