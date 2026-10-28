<?php

declare(strict_types=1);

namespace App\Domain\Alerts\Problems;

use App\Domain\ProblemType;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The webhook is turned off (its receiver answered 410, or Discord 404 or 401): 409 webhook-disabled.
 * Delete it and add it again.
 */
final class WebhookDisabled extends HttpException implements ProblemType
{
    public function __construct()
    {
        parent::__construct(409, 'The webhook is turned off.');
    }

    public function slug(): string
    {
        return 'webhook-disabled';
    }

    public function title(): string
    {
        return 'Webhook Turned Off';
    }

    public function extra(): array
    {
        return [];
    }
}
