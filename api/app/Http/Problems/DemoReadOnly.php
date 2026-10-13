<?php

declare(strict_types=1);

namespace App\Http\Problems;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** The read-only demo account asked to change something: 403 demo-read-only (plan P4-i1 D4, D8). */
final class DemoReadOnly extends HttpException
{
    public function __construct()
    {
        parent::__construct(403, 'The demo account is read-only.');
    }
}
