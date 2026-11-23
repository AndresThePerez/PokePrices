<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Alerts\DemoSamples;
use App\Http\Resources\DemoSamplesResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[Group('Account', 'The signed-in PokéPrices account (cookie session; sign in through the session endpoints first).')]
final class DemoSamplesController
{
    /**
     * Read the demo's samples.
     *
     * For the read-only demo account only: its alert (the watchlist card and threshold), a sample delivered alert email as the account would have received it (subject and text), and a sample signed webhook delivery (price.moved): the exact body, the webhook-id, webhook-timestamp and webhook-signature headers it was signed with, and the webhook's url_hint. sample_secret is the fixed, published sample signing secret the signature verifies with; it belongs to no account. Nothing is ever sent for the demo. alert, message and webhook are null when the demo has none. Any other account gets 404 not-found; signed out, 401. The answer is never cached.
     *
     * @operationId getDemoSamples
     *
     * @throws NotFoundHttpException<404> when the signed-in account is not the demo
     * @throws HttpException<500> when the samples cannot be read (problem type server-error)
     */
    public function __invoke(ConnectionInterface $db, #[CurrentUser] User $user): DemoSamplesResource
    {
        if (! $user->is_demo) {
            throw new NotFoundHttpException;
        }

        return new DemoSamplesResource(DemoSamples::read($db, $user->id));
    }
}
