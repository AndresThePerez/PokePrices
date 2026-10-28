<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Alerts\NotificationSettings;
use App\Http\Resources\NotificationSettingsResource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

#[Group('Account', 'The signed-in PokéPrices account (cookie session; sign in through the session endpoints first).')]
final class NotificationController
{
    public function __construct(private readonly NotificationSettings $settings) {}

    /**
     * Read where alerts go.
     *
     * Where the signed-in account's alerts and daily digest are sent: channel email (the account's email address) or discord (its Discord webhook); digest says whether a daily digest is sent, once per collected day, listing the watched cards that moved; discord_available says whether the account has a Discord webhook that is not turned off. Without a saved choice the answer is email with no digest. The answer is never cached.
     *
     * @operationId getNotifications
     */
    public function show(Request $request): NotificationSettingsResource
    {
        return new NotificationSettingsResource($this->settings->of(self::userId($request)));
    }

    /**
     * Choose where alerts go.
     *
     * Saves where the signed-in account's alerts and daily digest are sent (channel email or discord) and whether a daily digest is sent (digest true or false); the answer is the same body as GET. Choosing discord needs a Discord webhook that is not turned off (422 errors.channel otherwise). Only a verified account may choose (403 email-not-verified); the demo account is read-only (403 demo-read-only).
     *
     * @operationId putNotifications
     *
     * @throws ValidationException 422 errors.channel or errors.digest
     */
    public function put(Request $request): NotificationSettingsResource
    {
        $validated = $request->validate([
            'channel' => ['bail', 'required', 'string', Rule::in(NotificationSettings::CHANNELS)],
            'digest' => ['bail', 'required', 'boolean:strict'],
        ], [
            'channel.required' => NotificationSettings::CHANNEL,
            'channel.string' => NotificationSettings::CHANNEL,
            'channel.in' => NotificationSettings::CHANNEL,
            'digest.required' => NotificationSettings::DIGEST,
            'digest.boolean' => NotificationSettings::DIGEST,
        ]);
        $userId = self::userId($request);

        $this->settings->save($userId, (string) $validated['channel'], $validated['digest'] === true, CarbonImmutable::now());

        return new NotificationSettingsResource($this->settings->of($userId));
    }

    /** auth:sanctum on the account group has already answered 401 to a signed-out request. */
    private static function userId(Request $request): int
    {
        /** @var User $user */
        $user = $request->user();

        return $user->id;
    }
}
