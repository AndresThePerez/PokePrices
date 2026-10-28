<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Accounts\AccountLimits;
use App\Domain\Accounts\VariantKey;
use App\Domain\Alerts\AlertThresholds;
use App\Domain\Pricing\MovementRule;
use App\Http\Resources\AlertResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

#[Group('Account', 'The signed-in PokéPrices account (cookie session; sign in through the session endpoints first).')]
final class AlertController
{
    public function __construct(private readonly AlertThresholds $thresholds) {}

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
        // The floor comes from the movement rule; every refusal of pct shows the same range.
        $message = 'Choose a whole percentage from '.MovementRule::alertFloor().' to '.AlertThresholds::MAX.'.';
        $request->validate([
            'pct' => ['bail', 'required', 'integer:strict', 'min:'.MovementRule::alertFloor(), 'max:'.AlertThresholds::MAX],
        ], [
            'pct.required' => $message,
            'pct.integer' => $message,
            'pct.min' => $message,
            'pct.max' => $message,
        ]);
        $pct = $request->integer('pct');
        $this->thresholds->set(self::userId($request), $variantKey, $pct);

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
            $this->thresholds->clear(self::userId($request), $variantKey);
        }

        return response()->noContent();
    }

    /** auth:sanctum on the account group has already answered 401 to a signed-out request. */
    private static function userId(Request $request): int
    {
        /** @var User $user */
        $user = $request->user();

        return $user->id;
    }
}
