<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Accounts\AccountLimits;
use App\Domain\Accounts\VariantKey;
use App\Http\Resources\AlertResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

#[Group('Account', 'The signed-in PokéPrices account (cookie session; sign in through the session endpoints first).')]
final class AlertController
{
    public const string NOT_WATCHED = 'Add this variant to your watchlist first.';

    /**
     * Set an alert on a watchlist card.
     *
     * Sets the alert threshold of one variant on the signed-in account's watchlist: a whole percentage from the movement rule's 5 (and at least $1.00) to 100. When that card's daily close moves by at least the threshold against the previous close, an alert is sent where alerts go (GET /v1/me/notifications). A key that names no PokéPrices variant or a variant not on the watchlist answers 422 with errors.variant_key; a pct that is not such a whole number answers 422 with errors.pct. Only a verified account may set alerts (403 email-not-verified); the demo account is read-only (403 demo-read-only).
     *
     * @operationId putAlert
     *
     * @throws ValidationException 422 errors.variant_key or errors.pct
     */
    public function put(Request $request, string $variantKey): AlertResource
    {
        // The key's shape is checked before any query (plan P4-i1 D5).
        if (! VariantKey::isValid($variantKey)) {
            throw ValidationException::withMessages(['variant_key' => [AccountLimits::UNKNOWN_VARIANT]]);
        }
        // The floor comes from config, so the range is checked after the type (the contract documents an integer).
        $floor = self::floor();
        $message = "Choose a whole percentage from {$floor} to 100.";
        $validated = $request->validate([
            'pct' => ['bail', 'required', 'integer:strict'],
        ], [
            'pct.required' => $message,
            'pct.integer' => $message,
        ]);
        $pct = $validated['pct'];
        if (! is_int($pct) || $pct < $floor || $pct > 100) {
            throw ValidationException::withMessages(['pct' => [$message]]);
        }

        $updated = DB::update('UPDATE watchlist_items SET alert_pct = ? WHERE user_id = ? AND variant_key = ?', [$pct, self::userId($request), $variantKey]);
        if ($updated === 0) {
            throw ValidationException::withMessages(['variant_key' => [self::NOT_WATCHED]]);
        }

        return new AlertResource($variantKey, $pct);
    }

    /**
     * Turn an alert off.
     *
     * Clears the alert threshold of the variant on the signed-in account's watchlist; 204 also when it had none, the variant is not on the watchlist or the key is malformed. The demo account is read-only (403 demo-read-only).
     *
     * @operationId deleteAlert
     */
    public function destroy(Request $request, string $variantKey): Response
    {
        if (VariantKey::isValid($variantKey)) {
            DB::update('UPDATE watchlist_items SET alert_pct = NULL WHERE user_id = ? AND variant_key = ? AND alert_pct IS NOT NULL', [self::userId($request), $variantKey]);
        }

        return response()->noContent();
    }

    /** The smallest threshold: the movement rule's percentage rounded up (no event exists below it, D9). */
    private static function floor(): int
    {
        $pct = config('pricing.movement.pct');

        return max(1, (int) ceil(is_numeric($pct) ? (float) $pct : 5.0));
    }

    /** auth:sanctum on the account group has already answered 401 to a signed-out request. */
    private static function userId(Request $request): int
    {
        /** @var User $user */
        $user = $request->user();

        return $user->id;
    }
}
