<?php

declare(strict_types=1);

namespace App\Http\Problems;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * No webhook of the signed-in account has this id: 404 webhook-not-found (plan P5-i1 D9). Another user's webhook is
 * answered the same way, so its existence is never revealed.
 */
final class WebhookNotFound extends HttpException
{
    public function __construct()
    {
        parent::__construct(404, 'No webhook of yours has this id.');
    }
}
