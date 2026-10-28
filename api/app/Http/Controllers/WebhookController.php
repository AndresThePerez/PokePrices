<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Alerts\AlertLimits;
use App\Domain\Alerts\TestMessage;
use App\Domain\Alerts\WebhookEndpoints;
use App\Domain\Alerts\WebhookSecret;
use App\Http\Requests\StoreWebhookRequest;
use App\Http\Resources\WebhookCreatedResource;
use App\Http\Resources\WebhookEndpointResource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

#[Group('Account', 'The signed-in PokéPrices account (cookie session; sign in through the session endpoints first).')]
final class WebhookController
{
    public function __construct(private readonly WebhookEndpoints $endpoints) {}

    /**
     * Read your webhooks.
     *
     * The signed-in account's webhooks, ordered by id: kind standard is a signed webhook (Standard Webhooks), kind discord a Discord webhook. url_hint is the URL's scheme and host only; the full URL and the signing secret are never shown again. disabled is true once the receiver said the webhook no longer exists (410, or Discord's 404 or 401). last_delivery is the newest message to it: its status and when (delivered, else created; RFC 3339 UTC), or null before the first. meta.limit is the most signed webhooks an account holds (plus one Discord webhook). The answer is never cached.
     *
     * @operationId getWebhooks
     */
    public function index(#[CurrentUser] User $user): AnonymousResourceCollection
    {
        $rows = $this->endpoints->rows($user->id);

        return WebhookEndpointResource::collection(array_map(fn (array $row): WebhookEndpointResource => new WebhookEndpointResource($row), $rows))
            ->additional(['meta' => [
                'count' => count($rows),
                'limit' => AlertLimits::webhooks(),
            ]]);
    }

    /**
     * Add a webhook.
     *
     * Registers a signed webhook (kind standard) or a Discord webhook (kind discord) for the signed-in account. The URL must be https and reach a public address (PokéPrices does not send to private, local or reserved networks); a Discord URL looks like https://discord.com/api/webhooks/<id>/<token>. The 201 answer carries the signing secret of a signed webhook, once: it is never shown again (to change it, delete the webhook and add it again); for Discord secret is null. An account holds at most 5 signed webhooks and one Discord webhook. Refusals answer 422 with errors.kind, errors.url or errors.description. Only a verified account may add webhooks (403 email-not-verified); the demo account is read-only (403 demo-read-only).
     *
     * @operationId createWebhook
     *
     * @throws ValidationException 422 errors.kind, errors.url or errors.description
     */
    public function store(StoreWebhookRequest $request, #[CurrentUser] User $user): JsonResponse
    {
        $kind = $request->kind();
        $url = $request->url();
        $description = $request->description();

        // The address guard resolves DNS, so it runs before the per-user lock is taken.
        [$error, $hint] = $this->endpoints->check($kind, $url);
        if ($error !== null || $hint === null) {
            throw ValidationException::withMessages(['url' => [$error ?? WebhookEndpoints::HTTPS]]);
        }

        $secret = $kind === 'standard' ? WebhookSecret::generate() : null;
        $created = $this->endpoints->create($user->id, $kind, $url, $hint, $description, $secret, CarbonImmutable::now());
        $rows = $this->endpoints->rows($user->id, $created);

        return (new WebhookCreatedResource($rows[0], $secret))->response()->setStatusCode(201);
    }

    /**
     * Delete a webhook.
     *
     * Deletes the signed-in account's webhook with this id, and every message still waiting for it. Deleting the Discord webhook while alerts go to Discord sets them back to email. The answer is 204 also when the account has no webhook with this id (nothing changes). The demo account is read-only (403 demo-read-only).
     *
     * @operationId deleteWebhook
     */
    public function destroy(#[CurrentUser] User $user, string $id): Response
    {
        $this->endpoints->delete($user->id, (int) $id);

        return response()->noContent();
    }

    /**
     * Send a test message.
     *
     * Queues one test message to the signed-in account's webhook with this id and answers 202 with its message_id (the webhook-id header of a signed webhook); it is sent within a few minutes. A signed webhook receives a webhook.test message that carries no price; a Discord webhook a short text. 404 webhook-not-found when the account has no webhook with this id; 409 webhook-disabled when the webhook is turned off. At most 5 test messages a minute (429). Only a verified account may send test messages (403 email-not-verified); the demo account is read-only (403 demo-read-only).
     *
     * @operationId testWebhook
     */
    public function test(#[CurrentUser] User $user, string $id): JsonResponse
    {
        $endpoint = $this->endpoints->testable($user->id, (int) $id);
        $delivery = TestMessage::queue($endpoint);

        return response()->json(['data' => ['message_id' => $delivery->message_id]], 202);
    }
}
