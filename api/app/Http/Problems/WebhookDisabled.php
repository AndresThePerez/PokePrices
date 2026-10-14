<?php

declare(strict_types=1);

namespace App\Http\Problems;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The webhook is turned off (its receiver answered 410, or Discord 404 or 401): 409 webhook-disabled (plan P5-i1 D9,
 * D7). Delete it and add it again.
 */
final class WebhookDisabled extends HttpException
{
    public function __construct()
    {
        parent::__construct(409, 'The webhook is turned off.');
    }
}
