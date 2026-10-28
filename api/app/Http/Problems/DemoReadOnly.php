<?php

declare(strict_types=1);

namespace App\Http\Problems;

use App\Domain\ProblemType;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** The read-only demo account asked to change something: 403 demo-read-only. */
final class DemoReadOnly extends HttpException implements ProblemType
{
    public function __construct()
    {
        parent::__construct(403, 'The demo account is read-only.');
    }

    public function slug(): string
    {
        return 'demo-read-only';
    }

    public function title(): string
    {
        return 'Demo Account Is Read-Only';
    }

    public function extra(): array
    {
        return [];
    }
}
